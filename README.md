<div align="center">

# Kashi · کاشی

**The code-first page builder for WordPress.**
Build every page tile by tile with your own HTML, CSS and JavaScript — written by hand or by AI — and see it live inside your real theme.

![Version](https://img.shields.io/badge/version-2.2.2-6c5ce7)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-2ea44f)
![Languages](https://img.shields.io/badge/languages-10-ff9f43)

[![Try it live](https://img.shields.io/badge/%E2%96%B6%20Try%20it%20live-WordPress%20Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/KaroRostampour/kashi/main/playground/blueprint.json)
[![دموی فارسی](https://img.shields.io/badge/%E2%96%B6%20%D8%AF%D9%85%D9%88%DB%8C%20%D8%B2%D9%86%D8%AF%D9%87-%D9%81%D8%A7%D8%B1%D8%B3%DB%8C-6c5ce7?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/KaroRostampour/kashi/main/playground/blueprint-fa.json)

<sub>Opens a private WordPress in your browser with Kashi and a demo page — nothing to install, nothing to sign up for.</sub>

[English](#english) · [فارسی](#فارسی)

</div>

---

## English

### Why Kashi?

Page builders like Elementor wrap a few lines of design in megabytes of CSS, JavaScript and nested `div`s. Kashi takes the opposite route: **you bring the code, Kashi gives you a fast, comfortable place to put it.**

Each part of a page is a *tile* — a self-contained piece of HTML, CSS and JS. Tiles stack into a page. Nothing global is loaded; a page ships only the code it actually uses.

It is built for designers who already write code, or who let ChatGPT, Claude or any other AI write it for them: paste the result, see it instantly, tweak it visually, publish.

### Highlights

| | |
|---|---|
| ⚡ **Live editor** | Full-screen editor with a real-theme preview at true device widths (1440 / 1280 / 768 / 390). |
| ✨ **15 ready-made sections** | Hero, features, steps, categories, product cards, stats, testimonials, brand logos, about, FAQ (with schema), CTA, contact, header and footer — responsive, accessible, Persian and English. Insert one, then make it yours with Direct edit. |
| 🎨 **Global colors & font** | Set your brand colors, corner radius and font once in *Kashi → Settings*; every ready-made section (and any code using `var(--kashi-accent)` …) follows. |
| 🧩 **Tiles** | Paste a whole HTML document or bare CSS/JS — Kashi detects the type and puts every part in the right place. Each tile's CSS is scoped to itself. |
| 🎨 **Direct edit** | Click any text, link, image, color or icon in the preview and change it without touching code. |
| 📐 **Spacing panel** | Elementor-style margin & padding with linked sides, units (px, %, em, rem, vh, vw) and separate values per device; background, corner radius, min-height, CSS ID and class. |
| 📱 **Per-device tiles** | Show a tile only on desktop, laptop, tablet or mobile — e.g. one hero for desktop and another for mobile. Duplicate H1s are fixed automatically for SEO. |
| ⏰ **Scheduled publishing** | Publish a tile at a set time and optionally hide it later (sales, campaigns). Works with LiteSpeed and other caches without manual purging. |
| 🧱 **Header & footer builder** | Replace the theme header/footer with tiles, with display conditions. Works with any theme. |
| 📚 **Library & history** | Save tiles to a library (as copies or linked), restore any of the last versions. |
| 🔣 **311 icons** | Minimal, product and brand icons, inline SVG, inherit text color, no external files. |
| 🔍 **SEO built in** | Content sync for Rank Math / Yoast, CSS and JSON-LD in `<head>`, lazy images, LCP priority, a live SEO checker. |
| 🌍 **10 languages** | English, Kurdish (Sorani), Persian, Arabic, Turkish, German, French, Spanish, Russian, Chinese — the whole UI, dates and times follow the language. Persian (Jalali) and Gregorian calendars. |
| 🔄 **Transfer** | Export tiles, headers/footers and pages to one file and import them on another site. |
| 🛡️ **Secure updates** | Updates straight from GitHub, verified with SHA-256; optional Ed25519-signed private update server and signed offline updates. |
| 🌐 **Offline mode** | A "national internet" mode that stops the dashboard from hanging on blocked foreign services, plus a bundled Vazirmatn font. |

### Try it first

Click **[▶ Try it live](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/KaroRostampour/kashi/main/playground/blueprint.json)** — WordPress Playground starts a throw-away WordPress site in your browser with Kashi installed and a demo page open in the editor. Everything stays in your browser and disappears when you close the tab.

### Installation

1. Download the latest `kashi.zip` from [Releases](https://github.com/KaroRostampour/kashi/releases) (or *Code → Download ZIP* and rename the folder inside to `kashi`).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Open **Pages**, hover a page and click **Edit with Kashi**.
4. Pick the language under **Kashi → Settings → Plugin language** (default: your dashboard language).

Updates appear under **Plugins** like any other plugin.

### Quick start

```html
<section class="hero">
  <h1>Hello, Kashi</h1>
  <p>Paste any HTML, CSS and JS here.</p>
</section>
<style>
  .hero { padding: 80px 24px; text-align: center }
  .hero h1 { font-size: 48px }
</style>
```

Paste it into a tile, watch the preview update, then use **Direct edit** or the **spacing panel** to polish it. Save — done.

Shortcode: `[kashi id="12"]` shows a library tile anywhere.

### Authenticity

Kashi is free software, so anyone may study, change and share it under the GPL. What cannot be faked is the **official build**: every release ships a `kashi-release.json` file listing the SHA-256 of every file, signed with the creator's Ed25519 key. *Kashi → About Kashi* shows whether the installed copy is **official and untouched** or **modified**. Get the official build only from this repository.

---

<div dir="rtl">

## فارسی

### چرا کاشی؟

صفحه‌سازهایی مثل المنتور برای چند خط طراحی، چند مگابایت CSS و جاوااسکریپت و `div` تودرتو به صفحه اضافه می‌کنند. کاشی برعکس است: **کد از شما، جای راحت و سریع برای آن از کاشی.**

هر قسمت صفحه یک «کاشی» است: HTML، CSS و JS خودش. کاشی‌ها کنار هم صفحه را می‌سازند. هیچ فایل سراسری‌ای لود نمی‌شود؛ هر صفحه فقط کدی را دارد که واقعاً لازم دارد.

کاشی برای طراحانی ساخته شده که خودشان کد می‌نویسند یا آن را از ChatGPT، Claude یا هر هوش مصنوعی دیگری می‌گیرند: کد را بچسبانید، همان لحظه نتیجه را ببینید، با کلیک ظاهرش را دقیق کنید و منتشر کنید.

### امکانات

- ⚡ **ویرایشگر زنده** تمام‌صفحه با پیش‌نمایش داخل قالب واقعی سایت، با عرض واقعی هر دستگاه (۱۴۴۰ / ۱۲۸۰ / ۷۶۸ / ۳۹۰)
- ✨ **۱۵ سکشن آماده**: هیرو، ویژگی‌ها، مراحل کار، دسته‌بندی‌ها، کارت محصول، آمار، نظرات مشتریان، لوگوی برندها، درباره ما، سؤالات متداول (با اسکیما)، فراخوان، تماس، هدر و فوتر؛ ریسپانسیو، فارسی و انگلیسی. درج کنید و با «ویرایش مستقیم» مال خودتان کنید
- 🎨 **رنگ‌ها و فونت سراسری**: رنگ برند، گردی گوشه‌ها و فونت را یک بار در «کاشی ← تنظیمات» بگذارید؛ همه سکشن‌های آماده (و هر کدی که از `var(--kashi-accent)` و … استفاده کند) خودکار عوض می‌شوند
- 🧩 **کاشی‌ها**: یک صفحه HTML کامل یا CSS/JS خام را بچسبانید؛ کاشی نوع کد را تشخیص می‌دهد و هر بخش را سر جایش می‌گذارد. CSS هر کاشی فقط روی خودش اثر دارد
- 🎨 **ویرایش مستقیم** متن، لینک، تصویر، رنگ و آیکون داخل پیش‌نمایش، بدون دست زدن به کد
- 📐 **پنل فاصله‌ها** به سبک المنتور: حاشیه خارجی و فاصله داخلی با قفل چهار طرف، واحد (px، %، em، rem، vh، vw) و مقدار جدا برای هر دستگاه؛ به‌علاوه پس‌زمینه، گردی گوشه، حداقل ارتفاع، شناسه و کلاس CSS
- 📱 **نمایش در دستگاه‌ها**: هر کاشی فقط در دسکتاپ، لپ‌تاپ، تبلت یا موبایل؛ مثلاً یک هیروی دسکتاپ و یک هیروی موبایل. H1 تکراری خودکار برای سئو درست می‌شود
- ⏰ **زمان‌بندی انتشار** با تاریخ شمسی و «پایان نمایش» اختیاری برای جشنواره‌ها؛ با LiteSpeed و کش‌های دیگر بدون پاکسازی دستی
- 🧱 **هدر و فوتر** سایت با کاشی، با شرط نمایش، سازگار با هر قالب
- 📚 **کتابخانه و تاریخچه نسخه‌ها**: کاشی کپی یا لینک‌شده و بازگردانی نسخه‌های قبلی
- 🔣 **۳۱۱ آیکون** مینیمال، محصول و برند؛ SVG داخلی، رنگ متن اطراف را می‌گیرد
- 🔍 **سئو**: همگام با Rank Math / Yoast، CSS و اسکیما در `<head>`، تصاویر lazy، اولویت LCP، بررسی‌کننده سئوی زنده
- 🌍 **۱۰ زبان**: انگلیسی، کوردی سورانی، فارسی، عربی، ترکی، آلمانی، فرانسوی، اسپانیایی، روسی و چینی؛ همه منوها، پیام‌ها، تاریخ و ساعت به همان زبان. تقویم شمسی و میلادی
- 🔄 **خروجی / ورودی**: انتقال کاشی‌ها، هدر/فوترها و صفحه‌ها بین سایت‌ها با یک فایل
- 🛡️ **آپدیت امن** مستقیم از گیت‌هاب با بررسی SHA-256؛ سرور آپدیت اختصاصی و آپدیت آفلاین با امضای دیجیتال Ed25519
- 🌐 **حالت اینترنت ملی** برای قطعی اینترنت بین‌الملل و فونت وزیرمتن محلی

### اول امتحان کنید

روی **[▶ دموی زنده فارسی](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/KaroRostampour/kashi/main/playground/blueprint-fa.json)** بزنید؛ یک وردپرس آزمایشی با کاشی و یک صفحه نمونه، داخل مرورگر خودتان باز می‌شود. بدون نصب و ثبت‌نام، و با بستن صفحه پاک می‌شود.

### نصب

۱. آخرین `kashi.zip` را از [Releases](https://github.com/KaroRostampour/kashi/releases) دانلود کنید (یا Code ← Download ZIP و نام پوشه داخل را `kashi` بگذارید).
۲. در وردپرس: «افزونه‌ها ← افزودن ← بارگذاری افزونه»، فایل را نصب و فعال کنید.
۳. در فهرست «برگه‌ها» روی «ویرایش با کاشی» بزنید.
۴. زبان افزونه را از «کاشی ← تنظیمات ← زبان افزونه» انتخاب کنید (پیش‌فرض: زبان پیشخوان).

نسخه‌های جدید مثل بقیه افزونه‌ها در «افزونه‌ها» با دکمه «به‌روزرسانی» نمایش داده می‌شوند.

### نسخه رسمی

کاشی نرم‌افزار آزاد با مجوز GPL است؛ هر کسی می‌تواند آن را بخواند، تغییر دهد و منتشر کند. چیزی که جعل‌شدنی نیست **نسخه رسمی** است: هر انتشار یک فایل `kashi-release.json` دارد که SHA-256 همه فایل‌ها را با کلید Ed25519 سازنده امضا می‌کند. صفحه «کاشی ← درباره کاشی» نشان می‌دهد نسخه نصب‌شده **رسمی و دست‌نخورده** است یا **تغییریافته**. نسخه رسمی را فقط از همین مخزن دریافت کنید.

</div>

---

## Developers

```
kashi.php                          core: editor, rendering, SEO, header/footer, styles, i18n
includes/class-kashi-updater.php   updates (GitHub, private server, signed offline) + settings
includes/class-kashi-net.php       offline ("national internet") mode, local font
includes/class-kashi-about.php     About page, creator credit, official-build check
languages/{code}.json              translations (Persian source text → language)
assets/                            icons, KR logo, Vazirmatn font (OFL)
templates/                         full-width and canvas layouts
assets/patterns.json               ready-made sections (Persian + English)
playground/                        WordPress Playground demo blueprints
```

**Translations.** UI strings are written in Persian and wrapped in `Kashi::t()` (PHP) or `T()` (editor JS). Each `languages/{code}.json` maps the Persian text to the translation; a missing entry falls back to Persian. Pull requests for new languages are welcome.

**Data compatibility.** Database keys from the predecessor *Lite Sections* (`_lsec_*` meta, the `lsec_section` post type, `lsec_revs_*`, the `[lsec]` shortcode) are kept on purpose. Don't rename them without a migration.

**Release.** Bump `Version:` and `const VERSION` in `kashi.php` and `Stable tag` in `readme.txt`, sign the build (`kashi-release.json`), push to `main`. Sites read the version from the latest commit; a GitHub Release with a `kashi-X.Y.Z.zip` asset takes priority.

## Creator

Designed and built by **[KaroRostampour](https://github.com/KaroRostampour)** · Karorostampoor@gmail.com

The name “Kashi” and the KR logo belong to the creator.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Icons: Lucide (ISC) and others, see `assets/ICONS-LICENSE.txt`. Font: Vazirmatn (SIL OFL).
