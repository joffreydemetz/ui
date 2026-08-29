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
| `JDZ\Ui\Html\Sanitizer` | Whitelist sanitiser for rich-text admin fields — the server half of the `jizy-editor` pair |
| `JDZ\Ui\Html\Helper` | WYSIWYG content normaliser (French typography, entity fixes, DOM tidy) — **not** a sanitiser |

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
// <img src="/thumbs/media_photos_le-parc.jpg-120.jpg" alt="Le parc" width="120" height="80"
//      loading="lazy" data-orientation="landscape" data-src="/media/photos/le-parc.jpg" />
```

`width`/`height` are the intrinsic size of the file in `src` (the thumb here),
so the browser reserves the slot before the lazy load — no layout shift. They
are hints, not sizing: with the `img { height: auto }` reset (jizy-basics ships
it) any CSS `width` keeps the ratio exactly as it would without them.

`data-src` pairs with a lazy loader (jizy-front/lozad swaps the thumb for the
original on scroll); `data-zoom` pairs with a picture viewer; both are inert
without JS.

## The rich-text whitelist

`Sanitizer::clean($html, $tier)` is what a rich-text admin field runs on save.
It applies a tag + attribute whitelist over a parsed DOM, then hands the result
to `Helper::clean()` for the French typography — in that order, because
`Helper::clean()` is `strip_tags`-based and is **not** a sanitiser: run first, it
would leave a `<script>` body behind as visible text.

```php
use JDZ\Ui\Html\Sanitizer;

Sanitizer::clean($posted, Sanitizer::FULL);       // whitelist + typography
Sanitizer::whitelist($posted, Sanitizer::FULL);   // the whitelist alone
```

| tier | tags kept |
|---|---|
| `MINIMAL` | `p br strong em a` |
| `FULL` | + `h2 h3 h4 u s ul ol li blockquote figure figcaption img` |

Attributes: `href` / `title` / `target="_blank"` on `<a>`, `src` / `alt` on
`<img>`, `class="pull-left|pull-right"` on `<figure>`, and an alignment on
`p h2 h3 h4 li` — accepted only as a lone `text-align: center|right|justify`
declaration (a legacy `align="…"` attribute is converted to it, `left` is
dropped, and any other property takes the whole `style` down with it).

### It is half of a pair

The other half is [`jizy-editor`](https://jizy.joffreydemetz.com/editor)'s
`lib/js/Sanitizer.js`, which enforces the same whitelist in the browser on paste.
The two are deliberate mirrors — same tags, same attributes, same renames, same
URL rules — and are checked against one shared corpus. A browser-side sanitiser
stops accidents, not attackers, so **the server is the one that decides**: when
they disagree, this class wins.

## Tests

```
composer test
```
