=== APGallery for Google Photos ===
Contributors: apsoft
Tags: gallery, google-photos, oauth, shortcode, lightbox
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de WordPress que integra Google Photos mediante la Picker API y OAuth 2.0.

== Description ==

Plugin de WordPress que integra Google Photos mediante la **Google Photos Picker API** (Google eliminó los scopes de la Library API el 31 de marzo de 2025, por lo que la Picker API es la única vía oficial para acceder a la biblioteca del usuario). Conecta tu cuenta de Google, elige qué fotos compartir desde el selector oficial, sincronízalas y muéstralas en cualquier página con [apgallery]. Grid responsive configurable, lightbox integrado, refresco automático de tokens y sanitización segura según estándares de WordPress.

Flujo de uso:

1. Configura tus credenciales OAuth 2.0 de Google Cloud (con la **Google Photos Picker API** habilitada).
2. Conecta tu cuenta de Google.
3. Pulsa "Elegir fotos de Google Photos", selecciona tus fotos en el selector de Google y pulsa "Hecho".
4. Pulsa "Sincronizar fotos seleccionadas": se importan a tu biblioteca de medios.
5. Muestra la galería con [apgallery] en cualquier página o entrada.

== Installation ==

1. Sube la carpeta del plugin a `/wp-content/plugins/` o instálalo desde el directorio de plugins de WordPress.
2. Activa el plugin desde el menú "Plugins".
3. Crea un proyecto en Google Cloud Console, habilita la **Google Photos Picker API** y crea credenciales OAuth 2.0 (aplicación web).
4. Copia la URL de redirección mostrada en los ajustes del plugin como redirección autorizada en Google Cloud Console.
5. Introduce tu Client ID y Client Secret en Ajustes → APGallery Google Photos y conecta tu cuenta.
6. Añade tu cuenta de Google como *test user* en el OAuth consent screen si la app está en modo "Testing".

== Frequently Asked Questions ==

= ¿Cómo muestro las fotos? =

Añade el shortcode [apgallery] en cualquier página o entrada. Puedes ajustar las columnas del grid con [apgallery columns="4"] (1-6). En móvil el grid pasa automáticamente a 2 columnas.

= ¿Por qué ya no se elige un álbum por ID? =

Google eliminó el 31 de marzo de 2025 los scopes de la Library API que permitían listar la biblioteca del usuario. Con la Picker API eliges directamente qué fotos compartir desde el selector oficial de Google, y el plugin las importa a tu biblioteca de medios. El shortcode muestra las fotos importadas.

= ¿Las fotos se guardan en mi sitio? =

Sí. Al sincronizar, las fotos se importan a la biblioteca de medios de WordPress, así que se sirven desde tu propio sitio y no dependen de URLs de Google que expiran.

== Changelog ==

= 0.6.1 =
* La sincronización muestra ahora los errores concretos de cada foto que no se pudo importar (antes se ocultaban y solo se ve00eda "0 fotos importadas")

= 0.6.0 =
* Migración a la Google Photos Picker API: Google eliminó los scopes de la Library API (photoslibrary.readonly) el 31/03/2025, lo que provocaba el error "Request had insufficient authentication scopes".
* Nuevo flujo: conectar cuenta → crear sesión de selección → elegir fotos en el selector de Google → sincronizar (importa a la biblioteca de medios).
* El shortcode [apgallery] muestra ahora las fotos importadas localmente (antes: [apgallery album="ID"]; el atributo album ya no es necesario).
* Las fotos se sirven desde el propio sitio (las baseUrl de Google expiran en ~1 h; los adjuntos locales no).
* Refresh token cifrado (AES-256-CBC + HMAC), refresco automático del access token y validación del scope concedido al conectar.

= 0.5.2 =
* Fix: las fotos no se mostraban nunca (el filtro usaba mimeType "PHOTO", que la API de Google Photos nunca devuelve; ahora se acepta cualquier mimeType image/*)
* Fix: el callback OAuth detecta ahora si Google concede un token sin el scope de fotos y muestra un aviso claro

= 0.5.1 =
* Fix: error de sintaxis PHP (comillas sin escapar en el JS del modal de medios) que hacia el plugin inutilizable al activarlo
* CI: matriz de tests con WordPress 6.4/latest, PHP 7.4-8.3 y multisite; 10 tests de integración (activación, shortcode, escapado XSS, cache y cifrado)

= 0.5.0 =
* Refresh token cifrado en la base de datos (AES-256-CBC + HMAC, clave derivada de wp_salt); migración automática de instalaciones previas
* uninstall.php: limpieza completa de opciones, transientes y metadatos al eliminar el plugin (los adjuntos importados no se tocan)
* Botón "Cargar más fotos" en la galería frontal con paginación AJAX y caché por página

= 0.4.0 =
* Caché de 5 minutos para la respuesta de la API del shortcode (atributo cache="0" para desactivarla por instancia)
* La caché se limpia automáticamente al conectar o desconectar la cuenta de Google
* Menos llamadas a la API en cada visita y protección de la cuota (las baseUrl de Google expiran en ~1 h)

= 0.3.0 =
* Nueva pestaña "Google Photos" en el modal de la biblioteca de medios del editor: elige un álbum, selecciona una foto e impórtala para insertarla como imagen normal.
* Reutiliza fotos ya importadas (marca "Ya en medios"), evitando duplicados en la biblioteca.
* Endpoints AJAX con verificación de permisos (upload_files) y nonce.

= 0.2.0 =
* Página Medios → Google Photos: visualiza álbumes y fotos, importa cualquier foto a la biblioteca de medios con un clic (evitando duplicados).

= 0.1.0 =
* Versión inicial: shortcode [apgallery], OAuth 2.0 con refresco de tokens, grid responsive y lightbox.
