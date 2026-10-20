# APGallery for Google Photos

Plugin de WordPress que integra Google Photos mediante la **Google Photos Picker API** y OAuth 2.0. Conecta tu cuenta de Google, elige qué fotos compartir desde el selector oficial y muéstralas en cualquier página con `[apgallery]`. Grid responsive configurable, lightbox integrado, refresco automático de tokens y sanitización segura según estándares de WordPress.

> **Nota importante:** Google eliminó el **31 de marzo de 2025** los scopes de la Library API (`photoslibrary.readonly`, `photoslibrary`, `photoslibrary.sharing`). Por eso este plugin usa la **Picker API**, la única vía oficial actual para acceder a las fotos del usuario.

## Características

- **Conexión OAuth 2.0** con la Google Photos Picker API y refresco automático de tokens
- **Selector oficial de Google Photos**: eliges tú qué fotos compartir (no la app)
- **Importación a tu biblioteca de medios**: las fotos quedan servidas desde tu propio hosting (las URLs de Google expiran en ~1 h; los adjuntos locales no)
- **Shortcode `[apgallery]`** con grid responsive (1-6 columnas) y lightbox integrado
- **Detección de duplicados**: una foto ya importada no se vuelve a descargar
- **Refresh token cifrado** en la base de datos (AES-256-CBC + HMAC, clave derivada de `wp_salt()`)
- **Validación de scope** en el callback OAuth: si Google no concede el permiso necesario, el panel te lo dice explícitamente

## Requisitos

- WordPress 6.0+
- PHP 7.4+
- Una cuenta de Google **sin Protección Avanzada** (ver [errores conocidos](#errores-conocidos))

## Instalación

1. Descarga el ZIP de la última [release](https://github.com/SirAntuans/APGallery-for-Google-Photos/releases).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin** → selecciona el ZIP → Instalar → Activar.

## Configuración de la cuenta de Google (paso a paso)

El plugin necesita un proyecto de Google Cloud con credenciales OAuth 2.0. Solo hay que hacerlo una vez.

### 1. Crear el proyecto

1. Entra en [Google Cloud Console](https://console.cloud.google.com/) con la cuenta de Google que quieres conectar.
2. Arriba, en el selector de proyectos → **Nuevo proyecto** → nómbralo (p. ej. "APGallery") → **Crear**.

### 2. Habilitar la API necesaria

1. En el menú: **APIs & Services → Library**.
2. Busca **"Google Photos Picker API"** → **Habilitar** (Enable).

   > Solo hace falta **esta** API. La "Photos Library API" ya no es útil para acceder a la biblioteca del usuario (Google la restringió a contenido creado por la app el 31/03/2025) — no la necesitas para este plugin.

### 3. Configurar el OAuth consent screen

1. **APIs & Services → OAuth consent screen** → **Get started / Configurar**.
2. Tipo de app: **External**.
3. Rellena nombre de la app y tu email de soporte.
4. **Scopes**: no es necesario añadir nada manualmente; el plugin solicita `https://www.googleapis.com/auth/photospicker.mediaitems.readonly` automáticamente. Si prefieres declararlo, añade ese mismo scope.
5. **Test users**: como la app está en modo **Testing**, añade aquí el email de la cuenta de Google que conectarás. **Sin este paso, Google bloqueará el acceso.**
6. Guarda. La app puede quedarse en modo Testing indefinidamente (los tokens de test expiran a los 7 días; reconecta si eso pasa — ver [errores conocidos](#errores-conocidos)).

### 4. Crear las credenciales OAuth 2.0

1. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
2. Tipo de aplicación: **Web application**.
3. En **Authorized redirect URIs** añade exactamente la URL que muestra el plugin en **Ajustes → APGallery Google Photos**, con esta forma:
   ```
   https://TU-DOMINIO.com/wp-admin/admin-post.php?action=apgallery_oauth_callback
   ```
4. **Crear** y copia el **Client ID** y el **Client Secret**.

### 5. Conectar en WordPress

1. **Ajustes → APGallery Google Photos**: pega Client ID y Client Secret → **Guardar credenciales**.
2. **Conectar con Google Photos** → inicia sesión con la cuenta (la misma que añadiste como test user) → acepta el consentimiento.
3. **Elegir fotos de Google Photos** → se abre el selector de Google → marca las fotos → **Hecho**.
4. Vuelve al panel → **Sincronizar fotos seleccionadas** → las fotos se importan a tu biblioteca de medios.
5. Añade `[apgallery]` a cualquier página o entrada.

Para añadir más fotos más adelante, repite los pasos 3-4 (cada selección crea una sesión nueva).

## Uso

```
[apgallery]
[apgallery columns="4"]
```

- `columns`: columnas del grid, 1-6 (por defecto 3). En móvil el grid pasa automáticamente a 2 columnas.
- Las fotos se muestran en una cuadrícula responsive con lightbox integrado.

## Errores conocidos

| Síntoma | Causa | Solución |
|---|---|---|
| `Error 400: policy_enforced` — "Protección Avanzada no ha aprobado…" | La cuenta de Google está inscrita en el [Programa de Protección Avanzada](https://g.co/advancedprotection), que bloquea apps no verificadas | Conecta con otra cuenta sin Protección Avanzada (puedes compartirle las fotos/álbum desde la cuenta protegida) o sal del programa |
| `Error 400: redirect_uri_mismatch` | La URI de redirección de Google Cloud no coincide con la del plugin | Copia la URL exacta que muestra el panel del plugin (con `https://` y sin barras finales) |
| `Error 400: admin_policy_enforced` o acceso bloqueado por el admin | La cuenta es de Google Workspace y el administrador bloquea apps de terceros | Usa una cuenta `@gmail.com` o pide al admin que permita el acceso |
| "Google no concedió el permiso de la Picker API…" | La Picker API no está habilitada en el proyecto, o el access token se obtuvo sin el scope | Habilita la Google Photos Picker API, revoca la app en [myaccount.google.com/permissions](https://myaccount.google.com/permissions) y reconecta |
| `(archivo.jpg) Forbidden` al sincronizar | Versiones del plugin ≤ 0.6.1 descargaban las fotos sin el header `Authorization` (la Picker API lo exige) | Actualiza a ≥ 0.6.2 |
| "Request had insufficient authentication scopes" | Uso de la Library API con scopes retirados por Google (31/03/2025) | Este plugin ≥ 0.6.0 ya no usa la Library API; si lo ves, actualiza |
| El token deja de funcionar a los ~7 días | App en modo Testing: los refresh tokens de test caducan semanalmente | Reconecta la cuenta, o publica la app (requiere verificación de Google) si quieres tokens permanentes |
| No aparece la cuenta correcta en la pantalla de Google | Sesión de navegador con otra cuenta activa | Usa una ventana de incógnito o cierra sesión de las demás cuentas de Google |

## Desarrollo

Para generar el ZIP instalable de una versión:

```bash
git tag v0.6.2
git push origin v0.6.2
```

Al publicar un tag `v*`, el workflow de GitHub Actions (`.github/workflows/release.yml`) crea el ZIP `apgallery-google-photos-X.Y.Z.zip` y lo adjunta automáticamente a la release correspondiente.

Tests: PHPUnit con el entorno de tests de WordPress (ver `tests/`). El CI corre lint PHP 7.4-8.3 y la matriz WP 6.4/latest × PHP 7.4/8.2 × multisite.

## Licencia

GPL-2.0-or-later
