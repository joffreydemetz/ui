# jdz/ui

Shared UI display layer for the JDZ packages — the base both
[`jdz/frontui`](https://jdz.joffreydemetz.com/frontui) and
[`jdz/adminui`](https://jdz.joffreydemetz.com/adminui) extend.

No framework, no Twig, no dependency beyond PHP 8.2.

## What's inside

| Class | Purpose |
|---|---|
| `JDZ\Ui\Html\Image` | `<img>` builder with on-demand GD thumbnails (width-constrained, cached under `public/thumbs/`), lazy loading, portrait/landscape hint, missing-source fallback, opt-in `data-zoom` |
| `JDZ\Ui\Html\Attributes` | Parse / merge HTML attribute strings |
| `JDZ\Ui\Html\Helper` | Abstract WYSIWYG content sanitiser (tag whitelist, French typography, DOM tidy) |

## Usage

```php
use JDZ\Ui\Html\Image;

$image = new Image(
    publicPath: __DIR__ . '/public',
    thumbsDir: 'thumbs',
    cacheLife: 0,
    fallbackSrc: '/media/share.png',
);

echo $image->render('media/photos/le-parc.jpg', 'Le parc', false, 120);
// <img src="/thumbs/media_photos_le-parc.jpg-120.jpg" alt="Le parc"
//      loading="lazy" data-orientation="landscape" data-src="/media/photos/le-parc.jpg" />
```

`data-src` pairs with a lazy loader (jizy-front/lozad swaps the thumb for the
original on scroll); `data-zoom` pairs with a picture viewer; both are inert
without JS.

## Tests

```
composer test
```
