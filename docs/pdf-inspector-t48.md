# qpdf / runner cô lập — yêu cầu triển khai T48

T23 đã được duyệt dùng qpdf, không thêm Composer package. Tài liệu này là yêu cầu phụ thuộc để T48 triển khai, **không đánh dấu T48 hoặc T23 hoàn tất**. PDF production còn tắt cho tới khi bộ mẫu/kiểm tra còn thiếu được chốt.

## Runtime và chính sách

Image `docker/pdf-inspector` chứa Ubuntu 24.04 (base digest cố định), qpdf từ gói Ubuntu và probe C do dự án tự biên dịch. Bản local đã kiểm tra: qpdf **11.9.0**. Qpdf là parser cấu trúc, không phải antivirus. Có thể nhận object stream nén qua JSON v2, giữ nguyên bản gốc, không ghi lại CV bằng qpdf.

`PdfSandbox` dùng Symfony Process đã có, mảng argv, không shell. Mỗi file có thư mục staging UUID 0700 riêng; chỉ bind đúng một file read-only vào container. File staging có read permission cho UID được map trong container, nhưng parent host 0700 chặn user khác; file gốc/private CV không đổi. Cleanup khôi phục read-only attribute trên Windows trước xóa. Container và staging được dọn trong finally; cleanup lỗi cũng từ chối.

Các giới hạn hiện tại: tổng ngân sách xử lý phía client 5 giây (cleanup tối đa thêm 3 giây); watchdog PID1 trong container tự kết thúc sau 5 giây kể từ lúc container chạy, chặn qpdf còn chạy nếu IPC Docker bị treo. RAM 256 MiB và không swap bổ sung, 1 CPU, 32 process, output mỗi lệnh tối đa 16 MiB, tối đa 10.000 mục object (kể cả trailer), sâu 32, tổng node/token khoảng 100.000. JSON được giới hạn trước decode và khi duyệt. Thiếu Docker/image/qpdf, lỗi/timeout/OOM/output limit/JSON hỏng/quá giới hạn đều từ chối. Không log JSON/parser stderr hoặc nội dung CV.

Danh sách chặn, trên cả key/name ở mọi object đã qpdf mở rộng, gồm tham chiếu gián tiếp: `JS`, `JavaScript`, `OpenAction`, `AA`, `Launch`, `GoToR`, `GoToE`, `SubmitForm`, `ImportData`, `XFA`, `RichMedia` và các tên bắt đầu RichMedia, `3D` và các tên bắt đầu 3D, `EmbeddedFile`, `EmbeddedFiles`, `Filespec`, `EF`. Binary PDF name chưa được hỗ trợ bị từ chối. Mọi PDF mã hóa, kể cả mở với mật khẩu rỗng, bị từ chối kèm hướng dẫn xuất lại không mã hóa. `qpdf --check` cảnh báo/lỗi cũng bị từ chối thay vì tự sửa.

Chính sách này có thể chặn file hợp lệ về cú pháp, ví dụ Word/LibreOffice có OpenAction chỉ mở trang đầu hoặc Canva có đính kèm. Đây là bộ từ chối cấu trúc đã được chốt, không phải kết luận mọi file bị chặn là malware. Không tự bỏ các marker, rewrite/flatten hoặc nới allowlist để làm mẫu qua test.

## Windows local / CI Ubuntu

Windows đã có Docker Desktop Linux engine. Ubuntu CI dùng Docker có sẵn. Build và tải corpus công khai trước khi chạy test; corpus ngoài Git được kiểm tra checksum và tải qua HTTPS:

```powershell
cd D:\AI\Viz-Ecom
$env:Path="$PWD\.tools\php;$env:Path"
docker build --tag viz-ecom-qpdf:local docker/pdf-inspector
php scripts/fetch-pdf-corpus.php
$env:PDF_SANDBOX_TESTS='1'
php .tools/composer.phar test --no-ansi
php .tools/composer.phar lint --no-ansi
```

Ubuntu với PHP/Composer/Docker đã cài:

```sh
docker build --tag viz-ecom-qpdf:local docker/pdf-inspector
php scripts/fetch-pdf-corpus.php
PDF_SANDBOX_TESTS=1 composer test
composer lint
```

CI bắt buộc `PDF_SANDBOX_TESTS=1`; nếu thiếu công cụ/image/corpus thì test lỗi, không skip. Lệnh test thông thường không có biến này có thể skip nhóm sandbox để dùng được khi máy chưa chuẩn bị Docker; không lấy lần chạy đó làm bằng chứng T23 đạt. Các PDF fixture tổng hợp thật đã commit, gồm JavaScript hộp thoại vô hại trong object stream và encryption. Script tái tạo chỉ dùng fixture giả: `php scripts/generate-pdf-security-fixtures.php`; không mở các PDF action bằng trình xem chạy JavaScript.

Local có thể đặt `PDF_INSPECTION_ENABLED=true` sau khi test runner đạt; chưa có form/endpoint T24 để nhận file qua web. Giữ `PDF_PRODUCTION_ENABLED=false`. Mẫu CV người dùng chỉ kiểm tra local, không commit hoặc đưa vào CI.

## Điều kiện máy chủ Ubuntu trước mở production

- qpdf phải nằm trong image đã build/kiểm tra từ nguồn cập nhật. Không chỉ cài `qpdf` trên host rồi chạy trực tiếp ngoài sandbox; driver hiện tại không có fallback native.
- Cài Docker rootless đúng hướng dẫn chính thức, gồm uidmap/subuid/subgid và cgroup v2 delegation. Dùng account ứng dụng phù hợp để daemon rootless đọc được staging; không cấp ứng dụng quyền vào rootful Docker socket hoặc sudo Docker. Cấu hình DOCKER_HOST/context đúng account; rootless engine phải có thật trong SecurityOptions. [Docker rootless](https://docs.docker.com/engine/security/rootless/).
- `APP_ENV=production` yêu cầu image **ID** cố định dạng `sha256:...`, không tag có thể đổi, và engine rootless; rootful engine bị từ chối ngay. Lấy ID đã kiểm tra bằng `docker image inspect --format '{{.Id}}' viz-ecom-qpdf:local`, đưa vào `PDF_INSPECTOR_IMAGE`.
- Mỗi lần kiểm tra đều xác minh HostConfig: network none, read-only, memory/swap/CPU/PID/cap-drop/no-new-privileges; thực tế chỉ có interface lo, memory.max đúng 256 MiB; probe chạy non-root, thử ghi vào rootfs phải lỗi read-only và TCP ra ngoài phải nhận ENETUNREACH. Nếu không bảo đảm được, từ chối và báo vận hành, không tắt probe.
- Test thật có tiến trình đòi RAM làm cgroup ghi `oom_kill`, tiến trình treo và output quá mức; đều từ chối, dọn container/staging. Chạy lại trên chính máy Ubuntu mục tiêu, không coi Docker Desktop/CI thay cho bằng chứng máy chủ.
- Kiểm tra ACL/owner private disk, không public symlink, quota scratch, quyền đọc/mở file theo T30–T35 và AV T27. Cập nhật/kiểm tra qpdf/base image khi vá bảo mật; pin lại ID chỉ sau test.
- Chỉ bật `PDF_PRODUCTION_ENABLED=true` sau khi đủ corpus và điều kiện review triển khai; hiện chưa được bật. Nếu rootless/cgroup/network/limits không bảo đảm trên server, giữ từ chối và báo người dùng, không thay bằng profile yếu hơn.

## Số đo và các mục còn thiếu

Local ngày 21/11/2026, Docker Desktop Linux 29.9.2 trên Windows, qpdf 11.9.0, quota 1 CPU/256 MiB. PDF ảnh RGB tổng hợp **10.485.760 byte**, ba lượt sau build mới nhất:

| Lượt | Thời gian runner, gồm cleanup | Peak cgroup memory |
| --- | --- | --- |
| 1 | 2.873 giây | 19.025.920 byte |
| 2 | 2.839 giây | 18.747.392 byte |
| 3 | 2.848 giây | 19.922.944 byte |

Median khoảng **2.848 giây**, peak khoảng **17,9–19,0 MiB**. Đây là một PDF ảnh tổng hợp hợp lệ, không phải worst case, không phải p95/benchmark tải đồng thời hoặc số đo production. Raw số đo chỉ có thông tin kỹ thuật ở `.runtime/t23-ten-mib-benchmark.json`.

Mẫu Word print, Google Docs và Chromium được nhận. Mẫu upstream Word 2016/LibreOffice có OpenAction bị chặn theo chính sách. Mẫu người dùng metadata Canva, 383.002 byte, bị chặn vì EmbeddedFiles/EmbeddedFile/EF/Filespec; khoảng 2.877 giây và 9.080.832 byte peak ở lần đo riêng. Không xuất nội dung hoặc tên CV vào báo cáo.

**Còn thiếu:** mẫu PDF điện thoại và Canva không đính kèm, kiểm tra runner rootless trên server thật, đo tải đồng thời/đa dạng CV. Tổng thời gian nộp hồ sơ chưa đo vì T24 chưa được làm; người dùng yêu cầu chờ T23 đủ mẫu mới sang T24. Parser/denylist không chứng minh phát hiện mọi exploit của PDF renderer, nội dung giả mạo hoặc malware mới; ClamAV và sandbox xem/chuyển đổi ở T27/T33 vẫn cần thiết.
