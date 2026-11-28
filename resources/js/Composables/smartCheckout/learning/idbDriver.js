/**
 * IndexedDB driver of the gallery cache (Phase 4), plus the in-memory driver the
 * Node tests and every unsupported browser fall back to.
 *
 * IndexedDB and not `localStorage` for two reasons: the gallery can be several
 * megabytes (localStorage is capped around 5 MB and stores UTF-16) and reading
 * it is synchronous, which would block the counter while the cache is parsed.
 *
 * Nothing here imports a library (no `idb`, no `localforage`). The database is
 * opened **once** (the promise is cached), the object store is created in
 * `onupgradeneeded`, and a failed or blocked open degrades to memory forever
 * after: Safari private mode or a browser with the storage disabled must never
 * break the POS, the worst case is downloading the gallery again.
 *
 * Driver contract (see galleryCache.js):
 *   {get(key), set(key, value), remove(key), keys()}
 */

export const IDB_DATABASE_NAME = 'ezyventas-smartpos';
export const IDB_DATABASE_VERSION = 1;
export const IDB_STORE_NAME = 'gallery';

/**
 * Driver backed by a `Map`. Same contract as the IndexedDB one, no browser
 * needed. Used as fallback and by the tests.
 *
 * @returns {{get: Function, set: Function, remove: Function, keys: Function}}
 */
export function createMemoryDriver() {
    const entries = new Map();

    return {
        async get(key) {
            return entries.has(key) ? entries.get(key) : null;
        },
        async set(key, value) {
            entries.set(key, value);
        },
        async remove(key) {
            entries.delete(key);
        },
        async keys() {
            return Array.from(entries.keys());
        },
    };
}

/**
 * Real driver: database `ezyventas-smartpos`, version 1, store `gallery`
 * (`keyPath: 'key'`, value `{key, value}`).
 *
 * @param {{indexedDB?: IDBFactory, dbName?: string}} [options]
 * @returns {{get: Function, set: Function, remove: Function, keys: Function}}
 */
export function createIndexedDbDriver({
    indexedDB: idb = globalThis.indexedDB,
    dbName = IDB_DATABASE_NAME,
} = {}) {
    if (!idb || typeof idb.open !== 'function') return createMemoryDriver();

    const fallback = createMemoryDriver();
    let opening = null;

    /**
     * Opens the database once. The promise resolves to the `IDBDatabase`, or to
     * `null` after the single attempt fails; it is never retried, so a browser
     * that denies IndexedDB does not pay for the failure on every operation.
     */
    const openDatabase = () => {
        if (opening !== null) return opening;

        opening = new Promise((resolve, reject) => {
            let settled = false;
            let request;

            try {
                request = idb.open(dbName, IDB_DATABASE_VERSION);
            } catch (error) {
                reject(error);
                return;
            }

            if (!request) {
                reject(new Error('IndexedDB no devolvió una petición de apertura.'));
                return;
            }

            request.onupgradeneeded = () => {
                try {
                    const database = request.result;
                    if (database && !database.objectStoreNames.contains(IDB_STORE_NAME)) {
                        database.createObjectStore(IDB_STORE_NAME, { keyPath: 'key' });
                    }
                } catch {
                    /* the open itself will fail and degrade to memory */
                }
            };

            request.onsuccess = () => {
                const database = request.result;

                if (settled) {
                    // `onblocked` already degraded us; release the connection so
                    // the other tab can upgrade.
                    try {
                        database?.close();
                    } catch {
                        /* already closed */
                    }
                    return;
                }

                if (!database) {
                    settled = true;
                    reject(new Error('IndexedDB devolvió una base de datos vacía.'));
                    return;
                }

                settled = true;

                database.onversionchange = () => {
                    try {
                        database.close();
                    } catch {
                        /* already closed */
                    }
                };

                resolve(database);
            };

            request.onerror = () => {
                if (settled) return;
                settled = true;
                reject(request.error || new Error('No se pudo abrir IndexedDB.'));
            };

            request.onblocked = () => {
                if (settled) return;
                settled = true;
                reject(new Error('IndexedDB está bloqueada por otra pestaña.'));
            };
        })
            .catch(() => null)
            .then((database) => database);

        return opening;
    };

    /**
     * Runs one operation inside a transaction. Never rejects: `{ok:false}` means
     * "use the memory fallback".
     */
    const runStore = async (mode, execute) => {
        const database = await openDatabase();

        if (!database) return { ok: false, value: undefined };

        return new Promise((resolve) => {
            let transaction;

            try {
                transaction = database.transaction(IDB_STORE_NAME, mode);
            } catch {
                resolve({ ok: false, value: undefined });
                return;
            }

            let request;

            try {
                request = execute(transaction.objectStore(IDB_STORE_NAME));
            } catch {
                try {
                    transaction.abort();
                } catch {
                    /* already finished */
                }
                resolve({ ok: false, value: undefined });
                return;
            }

            transaction.oncomplete = () => resolve({ ok: true, value: request ? request.result : undefined });
            transaction.onerror = () => resolve({ ok: false, value: undefined });
            transaction.onabort = () => resolve({ ok: false, value: undefined });
        });
    };

    return {
        async get(key) {
            const result = await runStore('readonly', (store) => store.get(key));

            if (!result.ok) return fallback.get(key);

            return result.value === undefined ? null : result.value;
        },
        async set(key, value) {
            const result = await runStore('readwrite', (store) => store.put({ key, value }));

            if (!result.ok) return fallback.set(key, value);

            return undefined;
        },
        async remove(key) {
            const result = await runStore('readwrite', (store) => store.delete(key));

            if (!result.ok) return fallback.remove(key);

            return undefined;
        },
        async keys() {
            const result = await runStore('readonly', (store) => store.getAllKeys());

            if (!result.ok) return fallback.keys();

            return Array.from(result.value || []);
        },
    };
}
