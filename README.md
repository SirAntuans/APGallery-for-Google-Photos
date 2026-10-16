# APGallery for Google Photos

Plugin de WordPress que integra Google Photos Library API mediante OAuth 2.0. Conecta tu cuenta de Google desde el panel de ajustes, elige un álbum y muéstralo en cualquier página con `[apgallery album="ID"]`. Grid responsive configurable, lightbox integrado, refresco automático de tokens y sanitización según estándares de WordPress.

## Características

- **Conexión OAuth 2.0** con Google Photos Library API y refresco automático de tokens
- **Panel de ajustes** (Ajustes → APGallery Google Photos): credenciales, conectar/desconectar cuenta y lista de álbumes con shortcodes listos para copiar
- **Página Medios → Google Photos**: explora los álbumes conectados e importa cualquier foto a tu biblioteca de medios de WordPress (con detección de duplicados)
- **Pestaña "Google Photos" en el modal de medios del editor**: elige una foto de la cuenta conectada e insértala como imagen normal
- **Shortcode `[apgallery album="ID"]`** con grid responsive (1-6 columnas) y lightbox integrado

## Instalación

1. Descarga el ZIP de la última [release](https://github.com/SirAntuans/APGallery-for-Google-Photos/releases) (o clona el repo).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin** → selecciona el ZIP → Instalar → Activar.
3. Crea un proyecto en [Google Cloud Console](https://console.cloud.google.com/), habilita la **Photos Library API** y crea credenciales OAuth 2.0 (aplicación web).
4. Copia la URL de redirección mostrada en los ajustes del plugin como redirección autorizada en Google Cloud Console.
5. Introduce tu Client ID y Client Secret en **Ajustes → APGallery Google Photos** y conecta tu cuenta.

## Uso

```
[apgallery album="ID_DEL_ALBUM"]
[apgallery album="ID_DEL_ALBUM" columns="4"]
```

Los IDs disponibles se muestran en el panel de ajustes tras conectar la cuenta.

## Desarrollo

Para generar el ZIP instalable de una versión:

```bash
git tag v0.3.0
git push origin v0.3.0
```

Al publicar un tag `v*`, el workflow de GitHub Actions (`.github/workflows/release.yml`) crea el ZIP `apgallery-google-photos-X.Y.Z.zip` y lo adjunta automáticamente a la release correspondiente.

## Requisitos

- WordPress 6.0+
- PHP 7.4+

## Licencia

GPL-2.0-or-later
