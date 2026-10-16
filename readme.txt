=== APGallery for Google Photos ===
Contributors: apsoft
Tags: gallery, google-photos, oauth, shortcode, lightbox
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de WordPress que integra Google Photos Library API mediante OAuth 2.0.

== Description ==

Plugin de WordPress que integra Google Photos Library API mediante OAuth 2.0. Conecta tu cuenta de Google desde el panel de ajustes, elige un álbum y muéstralo en cualquier página con [apgallery album="ID"]. Grid responsive configurable, lightbox integrado, refresco automático de tokens y sanitización según estándares de WordPress.

== Installation ==

1. Sube la carpeta del plugin a `/wp-content/plugins/` o instálalo desde el directorio de plugins de WordPress.
2. Activa el plugin desde el menú "Plugins".
3. Crea un proyecto en Google Cloud Console, habilita la Photos Library API y crea credenciales OAuth 2.0 (aplicación web).
4. Copia la URL de redirección mostrada en los ajustes del plugin como redirección autorizada en Google Cloud Console.
5. Introduce tu Client ID y Client Secret en Ajustes → APGallery Google Photos y conecta tu cuenta.

== Frequently Asked Questions ==

= ¿Cómo muestro un álbum? =

Añade el shortcode [apgallery album="ID_DEL_ALBUM"] en cualquier página o entrada. Puedes ver los IDs disponibles en el panel de ajustes del plugin una vez conectada tu cuenta.

= ¿Puedo cambiar el número de columnas? =

Sí, con el atributo columns (1-6): [apgallery album="ID" columns="4"]. En móvil el grid se ajusta automáticamente a 2 columnas.

== Changelog ==

= 0.2.0 =
* Nueva página Medios → Google Photos: visualiza los álbumes conectados y sus fotos, e importa cualquier foto a la biblioteca de medios con un clic (evita duplicados).
* Corregido: la lista de álbumes ahora usa query params en GET v1/albums (antes enviaba body en un GET).
* Corregido: las cabeceras se fusionan correctamente al reintentar tras un 401.
* Corregido: doble encoding eliminado en el redirect de error del callback OAuth.
* Nuevo: avisos de éxito/error del callback OAuth visibles en el panel de ajustes.

= 0.1.0 =
* Primera versión: conexión OAuth 2.0 con Google Photos Library API, panel de ajustes, shortcode [apgallery] con grid responsive (1-6 columnas) y lightbox integrado.
