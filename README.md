# Issues API (issueApiWrite)

Plugin genérico para OJS 3.3 que agrega endpoints de **escritura** (POST/PUT/DELETE)
a la API REST de issues/números, que el core solo expone como lectura.

## Identificación

| Dato | Valor |
|------|-------|
| Nombre visible | Issues API |
| Nombre técnico | `issueapiwriteplugin` |
| Carpeta | `plugins/generic/issueApiWrite` |
| Categoría | Genérico (generic) |
| Clase | `IssueApiWritePlugin` |

---

## Instalación y configuración (también para instalarlo en otro OJS)

Sigue estos pasos **en orden** antes de usar la API. Sin ellos el plugin no
funciona (verás `404 endpointNotFound` o `403 unauthorized`).

Esta misma guía sirve para **instalar el plugin en otra instalación de OJS**:
copia la carpeta (paso 3) y repite todos los pasos en ese OJS. El plugin no crea
tablas propias ni depende de datos de esta instalación.

### 1. Requisitos

- OJS **3.3.x**
- PHP con la extensión **`intl`** habilitada (la requiere OJS). En XAMPP,
  descomenta `extension=intl` en `php.ini` y reinicia Apache.

### 2. Configurar `api_key_secret` — **obligatorio**

Es la clave con la que OJS firma los tokens de API. **Si está vacía, no podrás
generar tu API token**: el perfil te mostrará el aviso "se requiere una clave
secreta" en lugar del token.

En `config.inc.php`, sección **`[security]`**:

```ini
[security]
api_key_secret = "pega-aqui-una-cadena-larga-y-aleatoria"
```

Para generar un valor seguro:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Notas:
- No hace falta reiniciar Apache: OJS lee `config.inc.php` en cada petición.
- Si cambias este valor, **todos los tokens ya generados dejan de funcionar**.

### 3. Instalar y habilitar el plugin

1. Copia la carpeta a `plugins/generic/issueApiWrite`.
2. Registra la versión:
   ```bash
   php lib/pkp/tools/installPluginVersion.php plugins/generic/issueApiWrite/version.xml
   ```
3. Habilítalo en **Ajustes → Sitio web → Plugins → Plugins genéricos**, marcando
   **"Issues API"**.

> ⚠️ **Importante:** `version.xml` debe declarar `<class>IssueApiWritePlugin</class>`.
> OJS solo carga plugins *lazy-load* en peticiones HTTP si el
> `product_class_name` registrado coincide con el nombre del plugin habilitado.
> Sin esa línea el plugin parece instalado pero **sus endpoints devuelven 404**.

### 4. Generar tu API token

1. Inicia sesión con un usuario que tenga rol **Gestor de la revista**
   (`ROLE_ID_MANAGER`). Los endpoints de escritura exigen ese rol.
2. Ve a tu **Perfil → pestaña "API Key"**.
3. Marca **"Enable external applications to use this API key"** y guarda.
4. Copia el **token JWT** que aparece. Ese es tu `Bearer <TOKEN>`.

### 5. Verificar que todo quedó bien

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "http://<host>/index.php/<revista>/api/v1/issues"
```

Debe devolver `200` con la lista de números. Si no:

| Síntoma | Causa probable |
|---------|----------------|
| El perfil no muestra el token | `api_key_secret` vacío (paso 2) |
| `404 endpointNotFound` en POST/PUT/DELETE | Plugin no habilitado, o falta `<class>` en `version.xml` |
| `403 unauthorized` | Falta el header `Authorization: Bearer`, o el usuario no es Gestor |
| `400 csrfInvalid` | Estás autenticando por sesión/cookie en vez de token |

### 6. (Opcional) Instalar plugins desde la interfaz de OJS

Si además vas a subir plugins desde la UI de OJS, configura el binario `tar` en
`config.inc.php`, sección `[cli]`. En Windows usa **barras normales** (con
`\` el valor se corrompe al leer el INI):

```ini
[cli]
tar = "C:/Windows/System32/tar.exe"
```

### 7. Al instalarlo en otro OJS, revisa además

- **Plugin DOI:** si vas a usar el campo `doi`, habilita el plugin **DOI** en ese
  OJS (Ajustes → Sitio web → Plugins → Identificadores públicos).
- **Valores específicos de la instalación:** el código del plugin no tiene rutas
  fijas (usa el contexto actual), pero la **colección de Postman** y cualquier
  **script de prueba** contienen valores propios de una instalación —el path de
  la revista (p. ej. `jourMX`), el locale principal (p. ej. `es_ES`) y los IDs—.
  Ajústalos al nuevo OJS.
- **Versión:** debe ser **OJS 3.3.x**. La API interna cambió en 3.4+, así que el
  plugin no funcionará sin adaptación en versiones más nuevas.

---

## Colección de Postman

Importa [`postman_collection.json`](postman_collection.json) en Postman
(Import → File). Define las variables de colección `base_url`
(ej. `http://ojs.localhost`), `journal` (ej. `jourMX`) y `token` (tu JWT). La
autenticación Bearer se hereda en todas las peticiones.

## Autenticación

Resumen (los detalles de configuración están arriba, en *Configuración previa*):

- Todas las peticiones requieren un **API token** (JWT) del usuario.
- El usuario debe tener rol **Gestor de la revista** (`ROLE_ID_MANAGER`).
- Header: `Authorization: Bearer <TOKEN>`
- Con token, la validación **CSRF se omite** automáticamente (no hace falta
  enviar `X-Csrf-Token`).
- Alternativa: `?apiToken=<TOKEN>` en la URL, aunque se prefiere el header.

> **Excepción — carga masiva de journals.** Los endpoints de *carga masiva /
> exportación de journals* (sección siguiente) son operaciones **de sitio**, no
> de una revista: requieren rol **Administrador del sitio** (`ROLE_ID_SITE_ADMIN`)
> y se llaman a nivel de sitio (`/index/api/v1/...`), no bajo una revista.

## Carga masiva de journals (contexts)

Crear y exportar journals es una operación **a nivel de sitio**. Por eso estos
dos endpoints:

- Se llaman en la **API de sitio**: `http://<host>/index.php/index/api/v1/contexts/...`
  (el segmento `index` = sin revista). Si los llamas bajo una revista responden
  `404 siteWideEndpoint`.
- Exigen rol **Administrador del sitio** (`ROLE_ID_SITE_ADMIN`), con el mismo
  **API token** Bearer del admin.
- Están disponibles siempre que el plugin esté **instalado** (registrada su
  `version.xml`), independientemente de que esté habilitado por revista.

Cada journal se crea con toda su inicialización por defecto (locales, secciones,
roles, menús, géneros de archivo), igual que al crearlo desde la interfaz.

| Método | Ruta | Acción |
|--------|------|--------|
| POST | `/contexts/bulk` | Crear varios journals de una sola llamada |
| GET | `/contexts/export` | Exportar todos los journals (JSON o CSV) |

### Crear en masa — `POST /index/api/v1/contexts/bulk`

Cuerpo JSON con un arreglo `journals`. Cada elemento:

| Campo | Tipo | Notas |
|-------|------|-------|
| `urlPath` | string | **Obligatorio.** Ruta del journal; solo `[a-zA-Z0-9]` con `-`/`_` internos. Único |
| `name` | string u objeto `{locale:...}` | **Obligatorio.** Nombre del journal |
| `acronym` | string u objeto | Opcional; siglas |
| `description` | string u objeto | Opcional |
| `primaryLocale` | string | Opcional; por defecto el locale principal del sitio. Debe ser un locale soportado por el sitio |
| `supportedLocales` | array de locales | Opcional; idiomas de la **interfaz (UI)** de la revista. Ej: `["en_US","es_ES"]`. Por defecto los del sitio |
| `supportedFormLocales` | array de locales | Opcional; idiomas de **Formularios (Forms)**. Debe ser subconjunto de `supportedLocales` |
| `supportedSubmissionLocales` | array de locales | Opcional; idiomas de **Envíos (Submissions)**. Debe ser subconjunto de `supportedLocales` |
| `enabled` | bool | Opcional; por defecto `true` |

> Los campos multilingües aceptan un string simple (se guarda en el
> `primaryLocale` del journal) o un objeto `{ "en_US": "..." }`.
>
> **Configuración de idiomas.** `supportedLocales`/`supportedFormLocales`/
> `supportedSubmissionLocales` corresponden a las columnas *UI*, *Forms* y
> *Submissions* de **Ajustes → Configuración → Idiomas**. Todos los locales deben
> estar **instalados en el sitio**; el `primaryLocale` debe estar entre los de UI;
> y Forms/Submissions deben ser subconjunto de UI. Aceptan un array o una cadena
> `"en_US|es_ES"`.

```
POST /index.php/index/api/v1/contexts/bulk
Authorization: Bearer <TOKEN-de-admin>
Content-Type: application/json

{
  "journals": [
    {
      "urlPath": "EJB", "name": "ECORFAN Journal Bolivia", "acronym": "EJB",
      "primaryLocale": "en_US",
      "supportedLocales": ["en_US", "es_ES"],
      "supportedFormLocales": ["en_US"],
      "supportedSubmissionLocales": ["en_US"],
      "enabled": true
    },
    { "urlPath": "JAC", "name": "Journal Applied Computing", "acronym": "JAC", "primaryLocale": "en_US" }
  ]
}
```

Respuesta `201` — **cada elemento se procesa de forma independiente**; una fila
inválida se reporta y no detiene el resto:

```json
{
  "summary": { "received": 2, "created": 2, "skipped": 0, "errors": 0 },
  "created": [
    { "id": 5, "urlPath": "EJB", "name": "ECORFAN Journal Bolivia" },
    { "id": 6, "urlPath": "JAC", "name": "Journal Applied Computing" }
  ],
  "skipped": [],
  "errors": []
}
```

- `skipped`: journals cuya `urlPath` **ya existía** (la llamada es segura de
  repetir; no duplica).
- `errors`: fallos de validación por elemento (formato de `urlPath`, `name`
  vacío, `primaryLocale` no soportado por el sitio…), con el detalle en `errors`.

> El endpoint procesa hasta **25 journals por petición** (`400
> api.400.tooManyJournals` si se excede). Envía lotes más pequeños si tienes
> muchos.

### Exportar — `GET /index/api/v1/contexts/export`

Devuelve **todos** los journals, incluyendo su configuración de idiomas. Con
`?format=csv` entrega un CSV con las mismas columnas que acepta la carga masiva
(`urlPath,name,acronym,description,primaryLocale,supportedLocales,supportedFormLocales,supportedSubmissionLocales,enabled`),
de modo que puedes exportar y volver a importar. En el CSV las listas de locales
van separadas por `|` (ej. `en_US|es_ES`).

```
GET /index.php/index/api/v1/contexts/export
Authorization: Bearer <TOKEN-de-admin>
```
Respuesta `200`:
```json
{
  "itemsMax": 2,
  "items": [
    {
      "id": 5, "urlPath": "EJB", "name": "ECORFAN Journal Bolivia", "acronym": "EJB",
      "description": "", "primaryLocale": "en_US",
      "supportedLocales": ["en_US", "es_ES"],
      "supportedFormLocales": ["en_US"],
      "supportedSubmissionLocales": ["en_US"],
      "enabled": true
    }
  ]
}
```

CSV:
```
GET /index.php/index/api/v1/contexts/export?format=csv
```
Descarga `journals.csv` (UTF-8 con BOM, se abre bien en Excel).

## Endpoints

Base: `http://<host>/index.php/<revista>/api/v1/issues`

**Issues**

| Método | Ruta | Acción |
|--------|------|--------|
| POST | `/issues` | Crear un issue |
| PUT | `/issues/{id}` | Editar un issue (parcial) |
| PUT | `/issues/{id}/publish` | Publicar el número **y todos sus artículos** |
| DELETE | `/issues/{id}` | Eliminar un issue |

**Galeradas del issue** (archivo del número completo, ej. PDF)

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/issues/{id}/galleys` | Listar galeradas del issue |
| POST | `/issues/{id}/galleys` | Crear galerada desde un archivo subido |
| PUT | `/issues/{id}/galleys/{galleyId}` | Reemplazar el archivo / editar la galerada |
| DELETE | `/issues/{id}/galleys/{galleyId}` | Eliminar galerada (y su archivo) |

**Galeradas de artículo** (el PDF del artículo)

Base: `http://<host>/index.php/<revista>/api/v1/submissions`

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/submissions/{id}/publications/{pubId}/galleys` | Listar galeradas del artículo |
| POST | `/submissions/{id}/publications/{pubId}/galleys` | Agregar galerada (PDF) |
| PUT | `/submissions/{id}/publications/{pubId}/galleys/{galleyId}` | Reemplazar el archivo / editar la galerada |
| DELETE | `/submissions/{id}/publications/{pubId}/galleys/{galleyId}` | Eliminar galerada (y su archivo) |

**Artículos** (crear artículo básico con su PDF)

| Método | Ruta | Acción |
|--------|------|--------|
| POST | `/issues/{id}/articles` | Crear artículo (submission + publicación + galerada PDF) en el número, **sin publicar** |

**Autores** (contribuidores de una publicación)

Base: `http://<host>/index.php/<revista>/api/v1/submissions`

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/submissions/{id}/publications/{pubId}/authors` | Listar autores |
| POST | `/submissions/{id}/publications/{pubId}/authors` | Agregar autor |
| PUT | `/submissions/{id}/publications/{pubId}/authors/{authorId}` | Editar autor |
| DELETE | `/submissions/{id}/publications/{pubId}/authors/{authorId}` | Eliminar autor |

**Publicar / despublicar** (endpoints del **core**, no del plugin)

| Método | Ruta | Acción |
|--------|------|--------|
| PUT | `/submissions/{id}/publications/{pubId}/publish` | Publicar el artículo |
| PUT | `/submissions/{id}/publications/{pubId}/unpublish` | Despublicar el artículo |

**Leer / editar / eliminar artículo y metadatos** (endpoints del **core**)

Completan el CRUD del artículo. Funcionan con el mismo token de gestor.

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/submissions/{id}` | Leer el artículo (submission) |
| GET | `/submissions/{id}/publications/{pubId}` | Leer la publicación **con** metadatos (keywords, abstract…) |
| PUT | `/submissions/{id}/publications/{pubId}` | Editar metadatos (title, abstract, keywords, subjects…) |
| DELETE | `/submissions/{id}/publications/{pubId}` | Eliminar una publicación (solo si no está publicada) |
| DELETE | `/submissions/{id}` | Eliminar el artículo completo |

> Los endpoints de lectura (`GET /issues`, `GET /issues/{id}`, `GET /issues/current`)
> los provee el core de OJS, no este plugin.

## Campos del cuerpo — issues (JSON)

Para `POST`/`PUT` de issues. Todos opcionales; en PUT solo se actualiza lo que se envía.

| Campo | Tipo | Notas |
|-------|------|-------|
| `volume` | int | |
| `number` | string | |
| `year` | int | |
| `showVolume` | bool | Mostrar volumen en la identificación |
| `showNumber` | bool | Mostrar número |
| `showYear` | bool | Mostrar año |
| `showTitle` | bool | Mostrar título |
| `accessStatus` | int | Estado de acceso |
| `datePublished` | string | Fecha `YYYY-MM-DD` |
| `title` | objeto por locale | Ej: `{ "es_ES": "..." }` |
| `description` | objeto por locale | Ej: `{ "es_ES": "..." }` |
| `coverImageTemporaryFileId` | int | Portada del número; imagen subida antes a `temporaryFiles` (paso 1) |
| `coverImageLocale` | string | Locale al que aplica la portada (por defecto el principal) |
| `coverImageAltText` | string u objeto por locale | Texto alternativo de la portada |
| `doi` | string | DOI del número (ej. `10.1234/rev.2026.36`). Cadena vacía lo elimina |

**Regla:** al menos uno de `showVolume/showNumber/showYear/showTitle` debe ser `true`.

**Portada:** la imagen debe ser un formato válido (png/jpg/gif). Se sube primero a
`temporaryFiles` (mismo paso 1 del ejemplo 5) y se pasa su id en
`coverImageTemporaryFileId`. Se guarda en `public/journals/{id}/` como
`cover_issue_{issueId}_{locale}.{ext}`.

---

## Ejemplos por endpoint

En todos los ejemplos se asume la revista `jourMX` y el header
`Authorization: Bearer <TOKEN>`.

### 1. Crear issue — `POST /issues`

```
POST /index.php/jourMX/api/v1/issues
Content-Type: application/json

{
  "volume": 18,
  "number": "36",
  "year": 2026,
  "showVolume": true,
  "showYear": true,
  "title": { "es_ES": "Número de prueba" }
}
```

Respuesta `201 Created` con el JSON del issue (incluye `id`).

**Con portada:** sube primero la imagen a `temporaryFiles` (paso 1 del ejemplo 5)
y añade su id:
```
POST /index.php/jourMX/api/v1/issues
Content-Type: application/json

{
  "volume": 18, "number": "36", "year": 2026,
  "showVolume": true, "showYear": true,
  "title": { "es_ES": "Número con portada" },
  "coverImageTemporaryFileId": 7,
  "coverImageAltText": "Portada del número 36"
}
```
La respuesta incluye `coverImageUrl` con la URL pública de la portada.

### 2. Editar issue — `PUT /issues/{id}`

Actualización parcial: solo se modifican los campos enviados.

```
PUT /index.php/jourMX/api/v1/issues/18
Content-Type: application/json

{
  "volume": 19,
  "number": "2"
}
```

Respuesta `200 OK` con el issue actualizado.

### 3. Eliminar issue — `DELETE /issues/{id}`

```
DELETE /index.php/jourMX/api/v1/issues/18
```

Sin cuerpo. Respuesta `200 OK` con el JSON del issue eliminado.

### 4. Listar galeradas — `GET /issues/{id}/galleys`

```
GET /index.php/jourMX/api/v1/issues/6/galleys
```

Respuesta `200`:
```json
{
  "items": [
    {
      "id": 2, "issueId": 6, "fileId": 2, "label": "PDF",
      "locale": "es_ES", "seq": 1, "urlPath": null,
      "urlPublished": "http://ojs.localhost/index.php/jourMX/issue/download/6/2"
    }
  ],
  "itemsMax": 1
}
```

### 5. Crear galerada (PDF) — `POST /issues/{id}/galleys`

Flujo de **2 pasos**, porque OJS separa el archivo de la galerada.

**Paso 1 — subir el archivo** al endpoint del core `POST /api/v1/temporaryFiles`
como `multipart/form-data` (cualquier nombre de campo; se toma el primero).
Devuelve `{ "id": <temporaryFileId> }`.

```
POST /index.php/jourMX/api/v1/temporaryFiles
Content-Type: multipart/form-data
  file=@numero.pdf
```

**Paso 2 — crear la galerada** con ese id:

```
POST /index.php/jourMX/api/v1/issues/6/galleys
Content-Type: application/json

{
  "temporaryFileId": 3,
  "label": "PDF",
  "galleyLocale": "es_ES"
}
```

Respuesta `201`:
```json
{
  "id": 2, "issueId": 6, "fileId": 2,
  "label": "PDF", "locale": "es_ES", "seq": 1, "urlPath": null,
  "urlPublished": "http://ojs.localhost/index.php/jourMX/issue/download/6/2"
}
```

Campos del cuerpo: `temporaryFileId` (int, **requerido**), `label` (string, por
defecto `"PDF"`), `galleyLocale` (string, por defecto el locale principal de la
revista), `urlPath` (string, opcional).

### 6. Eliminar galerada — `DELETE /issues/{id}/galleys/{galleyId}`

```
DELETE /index.php/jourMX/api/v1/issues/6/galleys/2
```

Sin cuerpo. Respuesta `200 OK`. Elimina también el archivo del disco.

### 7. Crear artículo básico + PDF — `POST /issues/{id}/articles`

Crea un artículo (submission + publicación) asignado al número, con su galerada
PDF. El artículo se crea **despublicado** (publicar es un paso aparte).

Igual que las galeradas, el PDF va en **2 pasos**: primero súbelo a
`POST /api/v1/temporaryFiles` (paso 1 del ejemplo 5) para obtener el
`temporaryFileId`, luego:

```
POST /index.php/jourMX/api/v1/issues/9/articles
Content-Type: application/json

{
  "title": "Título del artículo",
  "sectionId": 1,
  "locale": "es_ES",
  "authorName": "Juan Pérez",
  "temporaryFileId": 5,
  "galleyLabel": "PDF",
  "abstract": "Resumen del artículo.",
  "keywords": ["inteligencia artificial", "educación"],
  "subjects": ["tecnología"],
  "disciplines": ["Ciencias de la computación"]
}
```

Respuesta `201`:
```json
{
  "submissionId": 7,
  "publicationId": 5,
  "galleyId": 2,
  "issueId": 9,
  "status": "unpublished",
  "title": "Título del artículo",
  "sectionId": 1
}
```

Campos del cuerpo:

| Campo | Tipo | Notas |
|-------|------|-------|
| `title` | string u objeto `{locale: ...}` | **Requerido** |
| `sectionId` | int | Opcional; por defecto la 1ª sección de la revista |
| `locale` | string | Opcional; por defecto el locale principal |
| `authorName` | string | Opcional; crea un autor con ese nombre |
| `temporaryFileId` | int | Opcional; el PDF subido en el paso 1. Sin él, se crea el artículo sin galerada |
| `galleyLabel` | string | Opcional; etiqueta de la galerada (por defecto `"PDF"`) |
| `abstract` | string u objeto `{locale:...}` | Opcional; resumen |
| `keywords` | array `["a","b"]` u objeto `{locale:[...]}` | Opcional; palabras clave |
| `subjects` | array u objeto por locale | Opcional |
| `disciplines` | array u objeto por locale | Opcional |
| `supportingAgencies` | array u objeto por locale | Opcional; agencias de apoyo |
| `doi` | string | Opcional; DOI del artículo (ej. `10.1234/art.2026.1`) |

> `keywords`, `subjects`, `disciplines` y `supportingAgencies` son listas. Un
> array simple se guarda en el locale del artículo; para varios idiomas usa
> `{ "es_ES": ["..."], "en_US": ["..."] }`.

### 8. Autores — CRUD

Sobre la publicación de un artículo (usa el `submissionId` y `publicationId` que
devuelve el ejemplo 7).

**Listar** — `GET /submissions/{id}/publications/{pubId}/authors`
```
GET /index.php/jourMX/api/v1/submissions/8/publications/6/authors
```
Respuesta `200`: `{ "items": [ ... ], "itemsMax": N }`.

**Agregar** — `POST /submissions/{id}/publications/{pubId}/authors`
```
POST /index.php/jourMX/api/v1/submissions/8/publications/6/authors
Content-Type: application/json

{
  "givenName": "María",
  "familyName": "García",
  "email": "maria@example.com",
  "affiliation": "Universidad X",
  "country": "MX"
}
```
Respuesta `201` con el autor creado.

**Editar** — `PUT /submissions/{id}/publications/{pubId}/authors/{authorId}`
```
PUT /index.php/jourMX/api/v1/submissions/8/publications/6/authors/3
Content-Type: application/json

{ "affiliation": "Instituto Y", "familyName": "García López" }
```
Respuesta `200` con el autor actualizado. Actualización parcial.

**Eliminar** — `DELETE /submissions/{id}/publications/{pubId}/authors/{authorId}`

Respuesta `200`.

Campos del autor:

| Campo | Tipo | Notas |
|-------|------|-------|
| `givenName` | string u objeto `{locale:...}` | **Requerido** (nombre) |
| `familyName` | string u objeto | Opcional (apellido). Necesario para la búsqueda por nombre en ORCID |
| `email` | string | **Requerido** |
| `affiliation` | string u objeto | Opcional |
| `country` | string | Opcional; código ISO de 2 letras (ej. `"MX"`) |
| `biography` | string u objeto | Opcional |
| `orcid` | string | Opcional; ORCID iD o su URL. Ver *Autocompletado desde ORCID* |
| `fetchOrcidProfile` | bool | Opcional (por defecto `true`); `false` desactiva **toda** consulta a ORCID |
| `userGroupId` | int | Opcional; por defecto el 1er grupo con rol Autor |
| `includeInBrowse` | bool | Opcional; incluir en listados |
| `seq` | int | Opcional; orden |

> Los campos multilingües aceptan un string simple (se guarda en el locale
> principal) o un objeto `{ "es_ES": "..." }`.

#### Autocompletado desde ORCID

Al crear o editar un autor, el plugin **consulta el registro público de ORCID
antes de guardar** y rellena los campos que no enviaste: `givenName`,
`familyName`, `affiliation`, `biography` y `country`.

Para saber de qué persona se trata, el plugin sigue uno de dos caminos:

| Caso | Cómo identifica al autor |
|------|--------------------------|
| Envías `orcid` | Usa ese iD directamente |
| **No** envías `orcid` | **Busca por nombre**, y solo acepta el resultado si es único |

Reglas comunes a ambos:

- Los valores que tú envías **siempre ganan** sobre los de ORCID.
- Usa la **API pública** de ORCID: no requiere OAuth ni configurar el plugin
  *ORCID Profile*. Si ese plugin está habilitado y apunta al *sandbox*, se usa
  el sandbox de ORCID automáticamente.
- El iD se guarda siempre normalizado como `https://orcid.org/0000-0002-1825-0097`.
- `"fetchOrcidProfile": false` desactiva **toda** consulta: ni busca por nombre,
  ni lee el perfil. El `orcid` que envíes se valida y se guarda normalizado, pero
  no se rellena ningún otro campo.

##### Por ORCID iD

Acepta el iD (`0000-0002-1825-0097`) o la URL completa. Basta el ORCID y el email:

```
POST /index.php/jourMX/api/v1/submissions/8/publications/6/authors
Content-Type: application/json

{ "orcid": "0000-0002-1825-0097", "email": "josiah@example.com" }
```
Respuesta `201`:
```json
{
  "orcid": "https://orcid.org/0000-0002-1825-0097",
  "givenName": { "es_ES": "Josiah" },
  "familyName": { "es_ES": "Carberry" },
  "affiliation": { "es_ES": "Wesleyan University" }
}
```

Errores: `400` si el iD tiene formato inválido, o si no se pudo leer el registro
público (ORCID caído, iD inexistente, sin conexión).

##### Por nombre (sin `orcid`)

Si no mandas `orcid`, el plugin busca el nombre en ORCID y, si encuentra a la
persona, guarda su iD y rellena el resto del perfil:

```
POST /index.php/jourMX/api/v1/submissions/8/publications/6/authors
Content-Type: application/json

{
  "givenName": "Jorge Daniel",
  "familyName": "González-Hernández",
  "email": "jorge@example.com"
}
```
Respuesta `201` — el `orcid` y la `biography` los trajo el plugin:
```json
{
  "orcid": "https://orcid.org/0000-0002-7150-3719",
  "givenName": { "es_ES": "Jorge Daniel" },
  "familyName": { "es_ES": "González-Hernández" },
  "biography": { "es_ES": "Doctor en Estudios Latinoamericanos por la..." }
}
```

> ⚠️ **Un nombre no es un identificador único, y el plugin no adivina.** Un ORCID
> equivocado le acreditaría el artículo a otro investigador —con su afiliación y
> su biografía— y eso es peor que no guardar iD: un dato ausente se nota, uno
> incorrecto no, y queda firmado en el registro público del artículo.
>
> Por eso la búsqueda por nombre es deliberadamente conservadora:
>
> - Exige **`givenName` y `familyName`**. Un nombre suelto empareja demasiado
>   ancho y ni siquiera se consulta.
> - **Solo acepta un único resultado.** Si la búsqueda devuelve cero perfiles, o
>   dos o más, el autor se guarda **sin `orcid` y sin enriquecer**, con lo que
>   enviaste. No es un error: responde `201`/`200` igual.
>
> Ejemplo real: `Maria Garcia` devuelve **2585** perfiles en ORCID → se descarta
> y el autor se guarda solo con su nombre. `Jorge Daniel González-Hernández`
> devuelve 1 → se usa.

Si la búsqueda por nombre encuentra un iD pero después no se puede leer el
perfil, el plugin **descarta el iD** y guarda al autor sin `orcid`, en vez de
fallar: nunca pediste esa búsqueda, así que no debe tumbar tu petición. (Con un
`orcid` explícito sí es `400`, porque ahí el fallo es sobre algo que pediste.)

##### Qué esperar del enriquecimiento

ORCID solo devuelve **lo que cada investigador hizo público**, así que la
cobertura es parcial y desigual: es normal que un autor traiga `biography` pero
no `affiliation` ni `country`. Los campos que ORCID no expone quedan como los
enviaste (o vacíos). El plugin no puede completarlos.

### 9. Publicar / despublicar

**Publicar el número completo** — `PUT /issues/{id}/publish` (endpoint del **plugin**)
```
PUT /index.php/jourMX/api/v1/issues/6/publish
Authorization: Bearer <TOKEN>
```
Sin cuerpo. **Publica el número y todos sus artículos de una sola llamada:** marca
el número como publicado y **actual**, le pone `datePublished` (hoy) si no tenía,
y publica los artículos asignados al número —tanto los programados como los que
creaste con el ejemplo 7—. Con esto no hace falta publicar artículo por artículo.

Respuesta `200` con el issue, más el detalle de qué artículos salieron:
```json
{
  "id": 6, "volume": 18, "published": true,
  "articlesPublished": [ { "submissionId": 7, "publicationId": 5 } ],
  "articlesFailed": [
    { "submissionId": 9, "publicationId": 8,
      "errors": { "reviewStage": "..." } }
  ]
}
```

**Qué artículos se publican y cuáles no.** Cada artículo pasa por la misma
validación que usa la interfaz editorial de OJS (`validatePublish`). Un artículo
que no la pasa —sigue en revisión, fue rechazado, o le faltan metadatos
obligatorios— **se omite y se reporta en `articlesFailed`**; no se publica y
tampoco tumba la publicación del resto del número. El endpoint responde `200`
aunque haya artículos omitidos: revisa `articlesFailed`.

Esto hace que la llamada sea **segura de repetir**: corrige lo que falló y vuelve
a llamarla; publicará los rezagados sin tocar los que ya salieron.

> ⚠️ Publicar el número lo marca como **número actual** de la revista.

Los dos siguientes son endpoints del **core** para publicar un artículo suelto.
Ya no hacen falta en el flujo normal, pero siguen sirviendo para publicar o
retirar un artículo por separado, sin tocar el número.

**Publicar el artículo** — `PUT /submissions/{id}/publications/{pubId}/publish`
```
PUT /index.php/jourMX/api/v1/submissions/9/publications/7/publish
Authorization: Bearer <TOKEN>
```
Sin cuerpo. Respuesta `200` con la publicación (ya con `status` publicado). El
artículo aparece entonces en el número.

**Despublicar** — `PUT /submissions/{id}/publications/{pubId}/unpublish`
```
PUT /index.php/jourMX/api/v1/submissions/9/publications/7/unpublish
```
Sin cuerpo. Respuesta `200`.

Errores posibles: `400` si faltan metadatos requeridos para publicar
(`validatePublish` del core), `403` si ya estaba publicado/despublicado.

### 10. Leer / editar metadatos del artículo (endpoints del core)

**Leer** — `GET /submissions/{id}/publications/{pubId}` devuelve la publicación
completa, **incluyendo** `keywords`, `abstract`, `subjects`, `disciplines`,
`authors`, etc. (el `GET /submissions/{id}` a secas trae un resumen sin keywords).

**Editar** — `PUT /submissions/{id}/publications/{pubId}`:
```
PUT /index.php/jourMX/api/v1/submissions/8/publications/6
Content-Type: application/json

{
  "abstract": { "es_ES": "Nuevo resumen" },
  "keywords": { "es_ES": ["nueva", "clave"] },
  "title": { "es_ES": "Título corregido" }
}
```
Respuesta `200` con la publicación actualizada. Nota: aquí los campos
multilingües/listas se envían siempre como objeto por locale
(`{ "es_ES": [...] }`), a diferencia del atajo de string simple que acepta el
endpoint de creación del plugin.

### 11. Reemplazar un PDF (galerada)

El PDF se reemplaza con el `PUT` de la galerada, enviando un nuevo
`temporaryFileId` (subido antes a `temporaryFiles`). El archivo anterior se
elimina automáticamente.

**Galerada de issue:**
```
PUT /index.php/jourMX/api/v1/issues/6/galleys/2
Content-Type: application/json

{ "temporaryFileId": 9, "label": "PDF actualizado" }
```

**Galerada de artículo:**
```
PUT /index.php/jourMX/api/v1/submissions/8/publications/6/galleys/3
Content-Type: application/json

{ "temporaryFileId": 9, "label": "PDF v2" }
```

Ambos responden `200` con la galerada actualizada (el `fileId`/`submissionFileId`
cambia). Envía solo `label` (sin `temporaryFileId`) para renombrar sin
reemplazar el archivo.

> Nota: el PDF de artículo **no** se puede reemplazar con el endpoint de archivos
> del core (`PUT /submissions/{id}/files/{fileId}`) en modo headless — devuelve
> `403` porque exige asignación de etapa editorial. Por eso se usa este endpoint
> del plugin.

### 12. DOI

OJS asigna DOIs a **tres** objetos: el **número (issue)**, el **artículo
(publicación)** y la **galerada de artículo**. OJS **no soporta DOI en galeradas
de issue**.

El plugin acepta un campo `doi` en:

| Objeto | Endpoint |
|--------|----------|
| Número | `POST` / `PUT /issues` y `/issues/{id}` |
| Artículo | `POST /issues/{id}/articles` |
| Galerada de artículo | `POST` / `PUT .../publications/{pubId}/galleys[/{galleyId}]` |

```
PUT /index.php/jourMX/api/v1/issues/6
Content-Type: application/json

{ "doi": "10.1234/rev.2026.36" }
```

Comportamiento:

- **Formato validado:** debe empezar con `10.` seguido del prefijo del
  registrante (`10.1234/loquesea`). Si no, `400`.
- **Duplicados:** si el DOI ya está asignado a otro número, artículo o galerada
  de la revista, `400`.
- **Eliminar el DOI:** envía `"doi": ""` (o `null`).
- Si no envías `doi`, el DOI existente **no se toca**.

El DOI aparece como `pub-id::doi` en las respuestas de issue, y como `doi` en las
de artículo y galerada de artículo.

> Requiere que el plugin **DOI** de OJS esté habilitado (Ajustes → Sitio web →
> Plugins → Identificadores públicos) para que el DOI se muestre en la web.

### 13. ISSN de la revista (API del core)

El ISSN pertenece al **journal**, no al número ni al artículo. No requiere
un endpoint nuevo en este plugin. OJS 3.3 acepta `onlineIssn` (electrónico)
y `printIssn` (impreso) mediante su API de contextos:

```http
PUT /index.php/<revista>/api/v1/contexts/<journalId>
Authorization: Bearer <TOKEN>
Content-Type: application/json

{ "onlineIssn": "2007-3682" }
```

Usa la ruta de la **misma revista**, no `/index/api/v1`: OJS requiere el
contexto para editarlo. El token debe tener permisos de gestor en esa revista
o de administrador. OJS valida el ISSN. Envía solo el campo que corresponda
y verifica el resultado con `GET` a esa misma URL.

El endpoint de carga masiva de este plugin no incluye ISSN entre sus campos;
para esas revistas, guárdalo después de crearlas con el `PUT` anterior.

En la integración de ECORFAN, el alta de artículos envía el DOI capturado como
`article.doi` y el campo «Source / ISSN» como `journalMetadata.onlineIssn`.
Este último se considera **electrónico**. La API del backend también admite
`journalMetadata.printIssn` cuando el dato es impreso. Solo se completan ISSN
ausentes: si hay uno diferente en OJS, se devuelve un conflicto sin crear el
artículo. Vaciar el formulario no borra el ISSN de la revista.

El ISSN es un dato independiente: una vez guardado, permanece aunque falle
posteriormente la creación del artículo. Los DOI e ISSN de artículos ya
importados no se completan retroactivamente con este cambio; pueden corregirse
desde OJS. Guardar el DOI en OJS no equivale a registrarlo en Crossref.

Referencia: [esquema oficial de journal de OJS 3.3](https://github.com/pkp/ojs/blob/stable-3_3_0/schemas/context.json).

## CRUD por entidad

| Entidad | Crear | Leer | Editar | Eliminar |
|---------|:-----:|:----:|:------:|:--------:|
| Issue (número) | plugin | core | plugin | plugin |
| Portada del issue | plugin (POST/PUT issue) | core | plugin (PUT issue) | — |
| Galerada de issue | plugin | plugin | **plugin** | plugin |
| Artículo | plugin | core | core | core |
| Metadatos (keywords, abstract…) | plugin (al crear) | core | core | core (con la publicación) |
| Galerada de artículo (PDF) | **plugin** | **plugin** | **plugin** | **plugin** |
| Autores (+ORCID) | plugin | plugin | plugin | plugin |
| DOI (issue, artículo, galerada de artículo) | plugin | plugin | plugin | plugin (`doi: ""`) |
| Publicar el número | **plugin** | — | — | — |
| Publicar / despublicar artículo | core | — | core | — |

## Respuestas / errores

| Código | Significado |
|--------|-------------|
| 201 | Creado (POST) |
| 200 | OK (PUT / DELETE) |
| 400 | Validación fallida (cuerpo del error con los campos) |
| 401/403 | Token inválido o usuario sin rol de gestor |
| 404 | Issue o endpoint no encontrado |

## Notas

- Los issues se crean **despublicados** (aparecen en *Números futuros*).
- `DELETE` de un issue elimina tabla de contenidos y galeradas del issue, y
  despublica los artículos asignados. Usar con cuidado.
- `DELETE` de una galerada elimina también su archivo del disco.
- La subida de galerada usa el endpoint del core `temporaryFiles` (paso 1).
- Los **artículos** creados quedan despublicados (status "queued", etapa
  producción). Aparecen en el número al publicarlo con `PUT /issues/{id}/publish`,
  que los publica junto con el número (ejemplo 9).
- El PDF del artículo se enlaza automáticamente a su galerada vía
  `SubmissionFileDAO` (assoc REPRESENTATION).
- Los autores se enriquecen desde ORCID **solo al crearlos o editarlos**. Los que
  ya estén guardados no se actualizan solos: hay que volver a enviarlos con un
  `PUT` (y la publicación no puede estar publicada, o OJS responde
  `##author.editPublishedDisabled##`).
- El enriquecimiento por nombre consulta ORCID en cada `POST`/`PUT` de autor. Si
  cargas autores en lote, cuenta con ~1-2 peticiones HTTP extra por autor.
 
## Flujo típico (web → OJS)

1. Crear u obtener el **número**: `POST /issues` (ejemplo 1).
2. Subir el PDF: `POST /temporaryFiles` → `temporaryFileId`.
3. Crear el **artículo** con su PDF: `POST /issues/{id}/articles` (ejemplo 7).
   Devuelve `submissionId` y `publicationId`.
4. Cargar **autores**: `POST /submissions/{id}/publications/{pubId}/authors`
   (ejemplo 8). Manda el nombre partido en `givenName` + `familyName`: con eso el
   plugin intenta completar el perfil desde ORCID (*Autocompletado desde ORCID*).
5. Repetir 2-4 por cada artículo del número.
6. **Publicar el número completo**: `PUT /issues/{id}/publish` (ejemplo 9). Saca
   online el número y todos sus artículos de una vez. Revisa `articlesFailed` en
   la respuesta por si alguno se omitió.
