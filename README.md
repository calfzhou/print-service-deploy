# Docker

Dockerfile and related configurations for the print service.

## Image Provenance

The files in this repo are reverse-engineered from a pre-built Docker image. See `IMAGE_SOURCE` for the exact image ID, digest, and extraction timestamp.

When the upstream image updates (even at the same tag), re-extract and update all files plus `IMAGE_SOURCE`.

## Image Size Breakdown (~1.8 GB)

Measured from v1.2.0 image. The bulk of the image is document processing
tooling, not printer drivers.

| Component | Size | What / Why |
|---|---|---|
| LibreOffice + deps (LLVM, Mesa, ICU) | ~400 MB | Doc/spreadsheet/slides to PDF conversion |
| CJK Fonts | ~184 MB | `fonts-noto-cjk` ~80 MB, `arphic` ~60 MB, `wqy` ~24 MB, others |
| TexLive | ~168 MB | `texlive-extra-utils` provides `pdfjam` for PDF rotation |
| Shared libraries (rest) | ~240 MB | Ghostscript, ImageMagick, PHP, OpenSSL, etc. |
| Printer drivers + PPD databases | ~70 MB | gutenprint, hplip, foomatic-db, splix, etc. |
| System (dpkg, i18n, doc, perl) | ~60 MB | Package metadata, locale data, perl (texlive dep) |
| CUPS + filters | ~9 MB | Core print system |
| App code | <1 MB | printer_client.php, configs, scripts |

