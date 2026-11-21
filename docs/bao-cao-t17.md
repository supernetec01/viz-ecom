# T17 — Tìm kiếm không dấu

## Đã làm

Ô từ khóa GET `q` tìm chuỗi con trong tiêu đề tin hoặc tên công ty, không phân biệt dấu/hoa thường; kết hợp AND với bộ lọc T16, giữ thứ tự/phân trang T15. Chuẩn hóa Unicode NFKC và khoảng trắng, tối đa 200 ký tự; rỗng chỉ bỏ điều kiện tìm kiếm, chuỗi dài/sai kiểu/NUL trả 422 tiếng Việt thay vì lỗi server. Dùng tham số SQL; `%`, `_`, `!` được escape thành ký tự thường, không mở rộng kết quả. Nội dung ô tìm kiếm được thoát ký tự khi hiển thị.

Migration mới `2026_11_21_000012_add_unaccent_job_search.php` bật `unaccent` và `pg_trgm` trong public, thêm `search_text` cho jobs/companies, backfill bản ghi cũ và tạo hai GIN index `gin_trgm_ops`. Trigger duy trì chuỗi chuẩn hóa khi insert/update, kể cả SQL trực tiếp, không sửa nội dung gốc. Dùng function STABLE và cột lưu sẵn, không khai báo unaccent thành IMMUTABLE trái thực tế. Function dùng tên riêng `viz_*`, search_path cố định; CREATE OR REPLACE để migrate:fresh sau khi bỏ bảng vẫn chạy được. Rollback bỏ trigger/index/cột/function của task, giữ extension có thể được dùng chung. Không sửa migration cũ, không thêm Composer/npm package.

`search_text` không mass assign và được ẩn khỏi JSON model. Truy vấn danh sách vẫn chỉ chọn cột công khai; tìm kiếm không gồm mô tả liên hệ, lý do duyệt, hồ sơ ứng viên hoặc người dùng nội bộ. Link phân trang giữ q và bộ lọc, form mới bắt đầu ở trang 1.

## Tiêu chí đạt và tên test

Trong `tests/Feature/PublicJobSearchTest.php`:

- `finds Vietnamese job titles without accents` (`ke toan` → `kế toán`).
- `finds company names such as Nguyen without accents and ignores letter case` (`Nguyen` → `Nguyễn`, `dong` → `Đồng`).
- `normalizes Unicode and whitespace while retaining the keyword in the form`.
- `treats wildcard characters as literal text and binds SQL special characters safely` (6 trường hợp).
- `treats empty and whitespace keywords as no search condition` (4 trường hợp).
- `rejects too long malformed and NUL keywords without a server error` (4 trường hợp, có chuỗi 10.000 ký tự).
- `escapes the search input without generating executable attributes`.
- `combines search and filters without allowing an OR match to expose a paused job`.
- `keeps the keyword in pagination links and stable result pages`.
- `updates searchable text for model and direct SQL changes without exposing the stored normalization`.
- `backfills existing rows and keeps data intact through a search migration rollback and reapply`.
- `installs the required extensions and makes normalized LIKE patterns eligible for trigram indexes`.

Đã có test đỏ trước triển khai. Local ngày 21/11/2026: `composer test` **397 passed, 1350 assertions**; `composer lint` (Pint/Larastan), `npm run build` xanh. Migration đã áp dụng trên DB demo, giữ nguyên nội dung tin/công ty và tài khoản.

Trình duyệt 360 × 800 chỉ kiểm tra phần mới/bị ảnh hưởng: `ke toan` ra 4 tin “kế toán”; `Nguyen` ra 15 tin của công ty “Nguyễn”, sang trang 2 còn 3, Back giữ từ khóa + loại công ty và đúng 12 ID trang đầu. Không cuộn ngang, console không lỗi; đã bỏ viewport thử. Công ty/15 tin fixture đã dọn, không tạo hay đổi quyền/mật khẩu tài khoản. Ảnh local bị Git bỏ qua: `.runtime/t17-search-mobile.jpg`, `.runtime/t17-search-results-mobile.jpg`.

## Chưa đạt, giả định và đề xuất

Không còn tiêu chí T17 chưa đạt trong local; chưa kiểm tra điện thoại thật/production hoặc đo tải trên dữ liệu lớn. Giả định tìm cả cụm từ theo chuỗi con trong tiêu đề hoặc tên công ty; chưa tự thêm sửa lỗi gõ, fuzzy ranking, gợi ý hoặc tìm trong nội dung dài. Kết quả vẫn ngày đăng mới nhất trước, không đổi thứ tự sang điểm tương đồng. Từ khóa rất ngắn hoặc dữ liệu nhỏ có thể dùng sequential scan; test chỉ chứng minh GIN đủ điều kiện dùng, không ép planner production hay coi là benchmark.

Đề xuất tiếp theo là T18 sau khi người dùng xem lại. Nếu đổi quy tắc unaccent/normalization sau này, cần migration cập nhật và backfill lại toàn bộ search_text; trigger tự duy trì index khi dữ liệu đổi.

## Triển khai và nguồn kỹ thuật

PostgreSQL vẫn là phiên bản 18 của dự án. Database dùng UTF-8; cần module unaccent/pg_trgm được cài và quyền CREATE trên database hoặc DBA cài trước vào public. Chạy `php artisan migrate` trước khi phục vụ mã mới và build asset. Migration có backfill/tạo index, cần thử staging và lên lịch phù hợp với dung lượng bảng. Không chạy migrate:fresh trên dữ liệu đang dùng; rollback cần phối hợp mã tương ứng và giữ extension dùng chung.

Đối chiếu tài liệu chính thức PostgreSQL 18: [unaccent](https://www.postgresql.org/docs/18/unaccent.html) cho bỏ dấu, [pg_trgm](https://www.postgresql.org/docs/18/pgtrgm.html) cho GIN hỗ trợ LIKE. Tính năng extension đã được người dùng duyệt trong phạm vi T17, không phải thêm thư viện PHP ngoài danh sách.
