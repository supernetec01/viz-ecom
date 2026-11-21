# T22 — Migration files và applications

## Đã làm

- Thêm migration `2026_11_21_000013_create_files_and_applications.php`, không sửa migration cũ. `files` lưu metadata: path unique, tên gốc, MIME, size, SHA-256, trạng thái quét mặc định pending, đường dẫn PDF xem trước tùy chọn và timestamps UTC. CHECK size 1–10 MiB, hash 64 ký tự hex, trạng thái pending/clean/infected.
- `applications` dùng ULID công khai làm primary key; có job_id/company_id, họ tên, phone_normalized, email/CV/portfolio tùy chọn, consent_given/consented_at, source, submitted_at, status mặc định new, internal_notes và deleted_at. Timestamps dùng timestamp with time zone, kết nối UTC.
- Khóa ngoại ghép `(job_id, company_id)` chỉ nhận đúng công ty chủ tin; thêm unique index phụ trên jobs qua migration mới. FK tới công ty/file dùng RESTRICT, không cascade xóa mất hồ sơ. Xóa mềm tin/công ty giữ nguyên hồ sơ và tham chiếu file.
- Unique partial `(job_id, phone_normalized) WHERE deleted_at IS NULL`: chặn trùng trong cùng tin, cho cùng SĐT ở tin khác hoặc sau xóa mềm; restore bị từ chối nếu tạo trùng. DB yêu cầu SĐT đã chuẩn hóa 0 + 9 chữ số, không tự đổi +84/84. Consent phải true và có thời điểm; nguồn đúng định dạng T21, portfolio URL hoặc file, không cả hai.
- Cập nhật test rollback T10 để gỡ bảng con T22 trước bảng jobs, đúng thứ tự phụ thuộc; không sửa nghiệp vụ T10. Có README/CODEMAP/checklist về ranh giới metadata, file thật, policy và retention.
- Đã áp dụng migration bổ sung lên DB demo local (66 ms), không migrate:fresh/seed hoặc sửa tài khoản. Không tạo dữ liệu ứng viên thật. Không thêm model, route, controller, form, đọc/tải/lưu file hay thư viện; ApplicationPolicy giữ nguyên chờ task phân quyền.

## Tiêu chí đạt và test

`tests/Feature/ApplicationStorageTest.php`:

- `rejects duplicate active normalized phones for the same job directly in PostgreSQL` (đã chạy đỏ trước khi viết migration).
- `rejects a company that does not own the referenced job even if both foreign rows exist`.
- `requires valid ULIDs normalized phones explicit consent safe source and valid application status in the database` (17 mẫu không hợp lệ, gồm FK).
- `stores a public ULID default new status nullable optional data and UTC submission timestamps`.
- `allows the same normalized phone on another job and a replacement after soft deletion but refuses restoration over a duplicate`.
- `preserves applications and file references when the job and company are soft deleted`.
- `restricts hard deletion of referenced jobs companies and files instead of silently cascading applications`.
- `stores file metadata with default pending scan status and supports optional preview paths`.
- `accepts a portfolio URL or file but rejects both together`.
- `rejects invalid file sizes hashes scan states and duplicate storage paths at the database boundary` (7 mẫu).
- `accepts the maximum file size and all documented application and scan statuses`.
- `can roll back and reapply only the new storage migration without losing existing company or job records`.

Test chịu ảnh hưởng tại `JobDataTest.php`: `preserves queue rows through migration up and down and keeps database queue usable`.

Ngày 21/11/2026: **504 test, 1726 assertions**, `composer lint` (Pint/Larastan) và `git diff --check` xanh. Test trên DB PostgreSQL riêng. T22 không đổi asset, dùng build T21 đã xanh, không chạy build hoặc kiểm tra lại giao diện không thay đổi.

## Tiêu chí chưa đạt

Không còn tiêu chí T22 chưa đạt trên local. Chưa kiểm tra migration trên staging/production. Chưa có nhận/lưu/đọc hồ sơ thật; đây là phạm vi T23/T24 và các task phân quyền tiếp theo, không phải chức năng demo được giả lập bằng dữ liệu thật.

## Giả định đã dùng

- ULID là mã công khai của hồ sơ; file ID nội bộ dùng bigint, tên file vật lý UUID do T23 thực hiện. T22 không kết luận file an toàn chỉ dựa vào MIME/hash trong DB.
- Hồ sơ còn hiệu lực trong unique index là bản ghi chưa xóa mềm. Xóa mềm hồ sơ không có đường thao tác ứng viên; không tạo chức năng xóa ở T22. Xóa mềm không thay thế xóa PII/file sau 6 tháng; T42/T46 xử lý theo phạm vi đã chốt.
- CV nullable theo mục 7.2; quy tắc yêu cầu CV theo tin/biểu mẫu thực hiện ở task tương ứng. DB kiểm tra dạng SĐT chuẩn; T24 cần kiểm tra giá trị và chuẩn hóa đầy đủ. Portfolio DB chỉ kiểm tra scheme/không whitespace, không thay validator URL của T24.
- Source nullable khi không có nguồn trong phiên; T24 lấy qua ApplicationSource::current, không từ POST. Trạng thái consent không có default tự đồng ý; dịch vụ phải ghi true sau khi người nộp thực sự đồng ý.

## Đề xuất thêm

T23 dùng metadata này sau khi kiểm tra nội dung thật/UUID/private disk. T24 xử lý vi phạm unique như lỗi nộp trùng và không log SQL bindings/QueryException có PII; lấy company_id và thời gian từ server. Các truy vấn ứng dụng sau này đi qua ApplicationPolicy, không coi ULID là kiểm soát truy cập. Thử thời gian tạo index trên staging trước phát hành. Dừng sau T22, chưa làm T23.
