# Feed Rewriter Plugin

WordPress plugin untuk mengambil feed RSS dan menulis ulang konten menggunakan OpenAI dengan teknologi AI generasi terbaru.

## Deskripsi

Feed Rewriter Plugin adalah plugin WordPress yang secara otomatis mengambil konten dari RSS feed, menulis ulang menggunakan OpenAI API, dan mempublikasikan sebagai artikel baru di website WordPress Anda.

## Fitur Utama

### 🤖 AI-Powered Content
- Menggunakan OpenAI GPT-4o Mini, GPT-4, GPT-5 dan model terbaru
- Rewrite konten menjadi lebih menarik dan unik
- Dukungan Bahasa Indonesia dan English

### 🔬 Enhanced Research Mode
- Mengumpulkan data tambahan dari sumber terkait di internet
- Membuat artikel yang lebih lengkap dan bernilai tinggi
- BIsa diatur 1-5 sumber tambahan

### 🖼️ Image Management
- Ekstrak gambar dari RSS feed
- Fallback: ambil gambar dari URL artikel
- Auto set featured image

### 🏷️ SEO Optimizations
- Meta tags otomatis (Open Graph, Twitter Card)
- Schema.org NewsArticle markup
- Auto generate tags dengan AI
- Table of Contents (TOC)

### ⚙️ Advanced Features
- 5 konfigurasi feed berbeda
- Keyword filter (include/exclude)
- Skip processed URLs
- Cron scheduling yang fleksibel
- Log rotation (5MB max)
- System requirements check

## Instalasi

1. Download plugin dalam bentuk ZIP
2. Upload ke folder `/wp-content/plugins/`
3. Aktifkan plugin melalui menu Plugins di WordPress
4. Buka Settings > Feed Rewriter untuk konfigurasi

## Konfigurasi

### Pengaturan Utama

1. **OpenAI API Key**: Masukkan API key dari [OpenAI Platform](https://platform.openai.com/)
2. **Model**: Pilih model AI (default: GPT-5.6 Luna)
3. **Language**: Pilih Bahasa Indonesia atau English
4. **Max Tokens**: Atur panjang artikel (default: 1500 untuk ~1000+ kata)

### Feed Configuration

Setiap feed bisa dikonfigurasi secara independen:
- **Feed URL**: URL RSS feed
- **Category**: Kategori WordPress untuk artikel
- **Interval**: Berapa menit sekali feed diproses
- **Custom Prompt**: Prompt kustom untuk OpenAI

### Fitur Tambahan

- **Enhanced Research Mode**: Aktifkan untuk hasil artikel yang lebih lengkap
- **Auto Tags**: Generate tags otomatis dengan AI
- **Enable TOC**: Table of Contents otomatis

## Requirements

- WordPress 5.0+
- PHP 7.4+
- OpenAI API Key
- minimal 64MB memory_limit PHP
- minimal 60 detik max_execution_time

## Harga API OpenAI

Plugin ini menggunakan model OpenAI dengan biaya sangat terjangkau:

| Model | Harga per 1M tokens |
|-------|---------------------|
| GPT-5.6 Luna | $0.10 |
| GPT-4.1 Nano | $0.10 |
| GPT-4o Mini | $0.15 |
| GPT-5 Mini | $0.15 |
| GPT-3.5 Turbo | $0.50 |
| GPT-4o | $2.50 |

**Estimasi biaya**:~$0.01-0.02 per artikel dengan GPT-4o Mini

## Changelog

### Version 1.1.0
- Tambah Enhanced Research Mode
- Tambah SEO Meta Tags
- Tambah Schema.org NewsArticle
- Tambah System Requirements Check
- Perbaikan cron scheduling
- Perbaikan caching untuk performa
- Dukungan GPT-5 model

### Version 1.0.0
- Rilis awal
- Basic RSS feed processing
- OpenAI rewrite
- Image extraction

## Lisensi

GPL v2 or later - lihat [License URI](https://www.gnu.org/licenses/gpl-2.0.html)

## Optimasi Kinerja

Plugin ini telah dioptimasi untuk meminimalkan penggunaan sumber daya server:

### Teknik Optimasi yang Diterapkan

1. **Option Caching**
   - Semua konfigurasi plugin disimpan dalam cache per-request
   - Mengurangi query database berulang selama proses generate artikel
   - Menggunakan `frp_preload_options()` untuk memuat semua setting sekaligus

2. **Article HTML Caching**
   - Halaman artikel disimpan dalam cache selama 12 jam
   - Menghindari download HTML yang sama berulang kali untuk ekstraksi gambar dan link
   - Mengurangi request HTTP keluar secara signifikan

3. **Async Post-Processing**
   - Operasi berat (download gambar, generate tags, SEO) diproses secara asynchronous
   - Menggunakan WordPress cron (`wp_schedule_single_event`) untuk pemrosesan di background
   - Mencegah timeout PHP dan menjaga cron utama tetap ringan

4. **Buffered Logging**
   - Log ditulis dalam buffer dan di-flush setiap 5 detik
   - Mengurangi operasi I/O file yang频繁
   - Log rotation otomatis ketika ukuran melebihi 5MB

5. **Regex Pre-check untuk Gambar**
   - Ekstraksi `og:image` dan `twitter:image` menggunakan regex cepat
   - DOMDocument hanya digunakan jika regex tidak menemukan gambar
   - Menghemat memory dan waktu processing

6. **Cleanup Stuck Async Posts**
   - Cron akan otomatis mendeteksi dan menjadwalkan ulang posting yang gagal diproses
   - Mencegah posting tersimpan tanpa metadata lengkap

### Rekomendasi Server

- **PHP memory_limit**: Minimal 256MB (disarankan 512MB untuk performa optimal)
- **max_execution_time**: Minimal 60 detik
- **WP_HTTP_REQUEST_TIMEOUT**: Minimal 30 detik
- **Cron interval**: 15-30 menit untuk menghindari rate limiting OpenAI API

### Penghematan Biaya API

- Default model: GPT-4o Mini ($0.15/1M tokens)
- Estimasi biaya: ~$0.01-0.02 per artikel
- Fitur Enhanced Research Mode bisa diatur 1-5 sumber tambahan sesuai budget

## Author

**Rohmat Ali Wardani**
- [LinkedIn](https://www.linkedin.com/in/rohmat-ali-wardani/)
- [GitHub](https://github.com/raw-dani)

## Support

Untuk pertanyaan dan diskusi, silakan buat issue di GitHub repository.

---

*Plugin ini tidak berafiliasi dengan OpenAI. Penggunaan API OpenAI sesuai dengan terms of service OpenAI.*
