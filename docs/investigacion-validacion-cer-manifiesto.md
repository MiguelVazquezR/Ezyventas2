# Investigación: Validación de archivos `.cer` y `.key` en el flujo de firma de manifiesto SAT

> **Fecha:** 2026-07-19
> **Módulo:** Billing → Fiscal Profiles → Manifest signing (3-step wizard)

---

## 1. Validación en el Frontend (Vue 3)

**Archivo:** `resources/js/Pages/Billing/FiscalProfiles/Show.vue`

### Step 1 — Input de `.cer`

```vue
<input
    type="file" accept=".cer"
    @input="legendCerFile = $event.target.files[0]"
    class="..."
/>
```

### Step 3 — Inputs de `.cer` y `.key`

```vue
<input type="file" accept=".cer" @input="signCerFile = $event.target.files[0]" ... />
<input type="file" accept=".key" @input="signKeyFile = $event.target.files[0]" ... />
```

**Hallazgo:** No hay ninguna validación frontend real. El atributo `accept=".cer"` solo es una sugerencia al navegador para filtrar el diálogo de archivos, pero **no es vinculante** — el usuario puede seleccionar cualquier archivo si cambia el filtro a "Todos los archivos". No existe `computed`, watcher, ni validación de extensión/tamaño previa al envío.

---

## 2. Validación en el Backend (Laravel 12)

### `FetchManifestLegendRequest.php` (Step 1)

```php
'cer_file' => [
    'required',
    'file',
    'mimes:cer',         // ← REGLA PROBLEMÁTICA
    'max:1024',
],
```

### `SignManifestRequest.php` (Step 3)

```php
'cer_file' => [
    'required',
    'file',
    'mimes:cer',         // ← REGLA PROBLEMÁTICA
    'max:1024',
],
'key_file' => [
    'required',
    'file',
    'mimes:key',         // ← REGLA PROBLEMÁTICA
    'max:1024',
],
```

**Hallazgo:** Ambas usan `mimes:cer` y `mimes:key`. No hay reglas custom, no hay `pathinfo()`, no hay validación manual de extensión.

---

## 3. Análisis de `mimes:cer` — El problema real

### ¿Cómo funciona la regla `mimes:` internamente?

Código fuente en `vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php` (línea 1663):

```php
public function validateMimes($attribute, $value, $parameters)
{
    if (! $this->isValidFileInstance($value)) {
        return false;
    }
    // ...
    return $value->getPath() !== '' && in_array($value->guessExtension(), $parameters);
}
```

La regla **NO valida la extensión del nombre del archivo**. Valida lo que devuelva `$value->guessExtension()`, cuyo flujo es:

```
UploadedFile::guessExtension()
  → MimeTypes::getDefault()->getExtensions( $this->getMimeType() )[0]
    → MimeTypes::getDefault()->guessMimeType($path)
      → FileinfoMimeTypeGuesser::guessMimeType()   ← usa finfo (PHP)
```

Es decir:

1. `finfo` analiza el **contenido binario** del archivo subido
2. Devuelve un MIME type (ej: `application/x-x509-ca-cert`)
3. Symfony busca las extensiones asociadas a ese MIME type en su mapa
4. Toma la **primera extensión** de la lista
5. La compara con los parámetros de la regla `mimes:` (`['cer']`)

### ¿Qué dice el mapa de Symfony?

En `vendor/symfony/mime/MimeTypes.php`:

```php
// MIME → extensiones
'application/pkix-cert'       => ['cer'],
'application/x-x509-ca-cert'  => ['der', 'crt', 'pem', 'cert'],

// Extensiones → MIME (reverse)
'cer'  => ['application/pkix-cert'],
'key'  => ['application/vnd.apple.keynote', 'application/pgp-keys', 'application/x-iwork-keynote-sffkey'],
```

### ¿Qué detecta realmente `finfo` para un `.cer` del SAT?

Un archivo `.cer` del SAT es un certificado X.509 en formato DER. `finfo` típicamente lo detecta como:

| MIME detectado por `finfo` | Extensiones asociadas | ¿Coincide con `['cer']`? |
|---|---|---|
| `application/x-x509-ca-cert` | `der`, `crt`, `pem`, `cert` | ❌ No (`guessExtension()` = `"der"`) |
| `application/pkix-cert` | `cer` | ✅ Sí |
| `application/octet-stream` | `bin` (u otros) | ❌ No (`guessExtension()` = `"bin"` o `null`) |

**Conclusión:** El resultado depende de la base de datos `magic.mime` del servidor (versión de PHP, SO, paquete `fileinfo`). En muchos entornos, `finfo` devuelve `application/x-x509-ca-cert` para certificados DER, lo que hace que `guessExtension()` devuelva `"der"` y la validación `mimes:cer` falle.

### ¿Y para `.key`? — Es peor

Un archivo `.key` del SAT es una llave privada en formato DER (PKCS#8). `finfo` lo detecta consistentemente como `application/octet-stream`, cuyas extensiones asociadas en Symfony son:

`bin`, `dms`, `lrf`, `mar`, `so`, `dist`, `distz`, `pkg`, `bpk`, `dump`, `elc`, `deploy`, `exe`, `dll`, `deb`, `dmg`, `iso`, `img`, `msi`, `msp`, `msm`, `buffer`, `com`, `class`, `vpm`

**Ninguna es `key`.**

**`mimes:key` siempre fallará, sin importar qué archivo `.key` subas.**

---

## 4. Diagnóstico propuesto

### Cómo confirmar el problema

Agregar esto temporalmente en `FiscalProfileController@fetchManifestLegend`:

```php
dd([
    'mime'     => $request->file('cer_file')->getMimeType(),
    'guessExt' => $request->file('cer_file')->guessExtension(),
    'origExt'  => $request->file('cer_file')->getClientOriginalExtension(),
]);
```

Resultado esperado en la mayoría de entornos:

```php
[
    'mime'     => 'application/x-x509-ca-cert',    // o 'application/octet-stream'
    'guessExt' => 'der',                            // o null
    'origExt'  => 'cer',
]
```

### Fix recomendado

Reemplazar `mimes:cer`/`mimes:key` por la regla `extensions:` de Laravel (disponible desde Laravel 8+), que valida contra `getClientOriginalExtension()` (la extensión real del nombre del archivo), no contra el MIME detectado:

#### `FetchManifestLegendRequest.php`

```php
'cer_file' => [
    'required',
    'file',
    'extensions:cer',
    'max:1024',
],
```

#### `SignManifestRequest.php`

```php
'cer_file' => [
    'required',
    'file',
    'extensions:cer',
    'max:1024',
],
'key_file' => [
    'required',
    'file',
    'extensions:key',
    'max:1024',
],
```

También actualizar los mensajes de error para reflejar la nueva regla:

```php
'cer_file.extensions' => 'El archivo debe tener extensión .cer.',
'key_file.extensions' => 'El archivo debe tener extensión .key.',
```

---

## Resumen

| Archivo | Regla actual | Problema | Fix |
|---|---|---|---|
| `.cer` (Step 1) | `mimes:cer` | `finfo` detecta `application/x-x509-ca-cert` → `guessExtension()` = `"der"` → falla | `extensions:cer` |
| `.cer` (Step 3) | `mimes:cer` | Ídem | `extensions:cer` |
| `.key` (Step 3) | `mimes:key` | `finfo` detecta `application/octet-stream` → `guessExtension()` = `"bin"` → **siempre falla** | `extensions:key` |
