/**
 * Regression test for the "Failed to execute 'postMessage' on 'Worker': #<Object>
 * could not be cloned" error.
 *
 * Vue wraps every object stored in a `ref` in a reactive Proxy, and the
 * structured clone used by `postMessage` cannot serialise proxies. Anything sent
 * to the inference worker therefore goes through `plainThresholds` /
 * `plainGallery`, which must produce structured-cloneable values.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';

import {
    plainGallery,
    plainThresholds,
} from '../../../resources/js/Composables/smartCheckout/inference/OnnxWebRecognitionEngine.js';

test('a Vue reactive object cannot be structured-cloned (the bug we fixed)', () => {
    const settings = reactive({ thresholds: { green_min: 0.85, green_margin: 0.08, yellow_min: 0.65 } });
    assert.throws(() => structuredClone(settings.thresholds), /could not be cloned|DataCloneError/i);
});

test('plainThresholds makes the thresholds cloneable for postMessage', () => {
    const settings = reactive({ thresholds: { green_min: 0.85, green_margin: 0.08, yellow_min: 0.65 } });

    const plain = plainThresholds(settings.thresholds);

    assert.doesNotThrow(() => structuredClone(plain));
    assert.deepEqual(structuredClone(plain), { green_min: 0.85, green_margin: 0.08, yellow_min: 0.65 });
    assert.equal(plainThresholds(null), null);
});

test('plainGallery makes the gallery cloneable and keeps the base64 payload', () => {
    const gallery = reactive({
        gallery_version: 'v1',
        model_version: 'dinov2-small-onnx-2026-11',
        dim: 384,
        items: [{ product_id: 1, offset: 0, length: 1536 }],
        vectors: 'AAAA==',
    });

    const plain = plainGallery(gallery);
    const cloned = structuredClone(plain);

    assert.equal(cloned.vectors, 'AAAA==');
    assert.deepEqual(cloned.items, [{ product_id: 1, offset: 0, length: 1536 }]);
    assert.equal(cloned.dim, 384);
    assert.equal(plainGallery(null), null);
});
