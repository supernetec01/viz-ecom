# T18 — Trang chi tiết tin tuyển dụng

## Đã làm

- GET `/viec-lam/{slug}-{id}`, tiêu đề danh sách dẫn tới chi tiết; kiểm tra công khai trước 301 slug cũ, không tiết lộ slug draft/pending. ID sai/quá bigint trả 404.
- Published còn hạn hiển thị đầy đủ và địa chỉ cụ thể. Paused/expired vẫn 200, có thông báo không nhận và gợi ý; quá hạn theo đồng hồ xử lý ngay không chờ T42.
- Closed 200 chỉ tiêu đề, tên công ty chữ thường (plain text), địa điểm, thông báo và gợi ý tối thiểu. SQL CASE trả null cho mô tả/yêu cầu/quyền lợi/lương trước render; không chỉ ẩn CSS. Không gửi metadata quét/người tạo/search_text/lịch sử duyệt.
- Trang không nhận có noindex; không JobPosting, form, POST hoặc route công ty tạm. Trang chi tiết no-store; không đổi CSP công khai. T20 phải dùng scope/hàm chung khi tạo sitemap/JobPosting.
- `Job::canReceiveApplications()` là hàm chung duy nhất nhận hồ sơ: published + đã tới ngày đăng + chưa tới hết hạn + chưa xóa mềm. View dùng trực tiếp; T24 phải dùng lại trên tin đọc mới qua public scope, không sao chép điều kiện.
- `publiclyViewable()` là nền chung detail/list/gợi ý để T43 thêm cờ gỡ một chỗ. Gợi ý tối đa 3 tin công khai cùng nhóm vị trí, ưu tiên cùng tỉnh rồi published_at/id mới trước, loại tin hiện tại.
- Tên công ty tập trung trong `public/jobs/company-name.blade.php`, chờ T19 đổi thành link. Đã có `docs/CODEMAP.md` và cập nhật các quyết định T18/T19/T24/T43 trong thiết kế.

## Tiêu chí đạt và tên test

`tests/Feature/PublicJobDetailTest.php`:

- `returns 404 without a canonical redirect or content for a draft`.
- `returns 404 for a pending job even when its id is known`.
- `renders a published job with its complete workplace and sanitized content`.
- `renders a paused job with full content a pause notice and noindex without an application form`.
- `renders an expired job with its full content expiry notice and noindex`.
- `returns only minimal closed job information and never its description requirements benefits or salary`.
- `returns 404 for missing malformed oversized and deleted job ids`.
- `redirects stale slugs to the current canonical slug with 301`.
- `treats overdue published or paused jobs as expired at the exact boundary without waiting for T42`.
- `returns 404 when the company is deleted unverified or its job was never publicly published`.
- `sanitizes legacy rich HTML and excludes internal review and search metadata from the public response`.
- `suggests only open jobs in the same category preferring the same province and excluding the current job`.
- `offers at most three stable minimal suggestions on every nonreceiving detail state`.
- `links public list titles to their canonical detail pages without a temporary company or POST route`.

`tests/Unit/JobApplicationAvailabilityTest.php`:

- `accepts applications only for published jobs among all approved states` (6 trạng thái).
- `stops receiving exactly at the expiry boundary` (trước/đúng/sau hạn).
- `does not receive applications for missing dates scheduled publication or a deleted job`.

Test chặn draft/pending có trước phần cho phép; published đỏ trước triển khai. Local ngày 21/11/2026: **430 test, 1469 assertions**, `composer lint` (Pint/Larastan) và `npm run build` xanh. Không thêm migration/dependency.

Trình duyệt: từ danh sách `q=T18&loai_cong_ty=direct&page=2` bấm tiêu đề mở chi tiết, Back giữ từ khóa/loại công ty/trang 2 và đúng ID. Published/paused/expired ở 360 px không tràn ngang; địa chỉ đầy đủ, nội dung/notice đúng. Slug cũ đi canonical. Closed desktop không có private sentinel, khối nội dung/lương/form; có noindex và 3 gợi ý; tên công ty không link. Console rỗng. Chỉ kiểm tra luồng mới/link danh sách bị ảnh hưởng, không kiểm tra lại quản trị. Đã dọn fixture một công ty/16 tin, không thay tài khoản.

Ảnh local (Git bỏ qua): `.runtime/t18-published-desktop.jpg`, `.runtime/t18-published-mobile.jpg`, `.runtime/t18-paused-mobile.jpg`, `.runtime/t18-expired-mobile.jpg`, `.runtime/t18-closed-desktop.jpg`; `.runtime/t18-console.json` rỗng. Đã reset viewport.

## Chưa đạt, giả định và đề xuất

Không còn tiêu chí T18 đã chốt chưa đạt trong local; chưa kiểm tra production/điện thoại thật. Published đủ điều kiện nhận hiển thị khu vực thông báo gửi hồ sơ chưa mở, không giả lập form/endpoint. Theo xác nhận người dùng, form/POST/lưu hồ sơ/file và test POST bị từ chối paused/expired/closed thuộc T22–T24; link công ty thuộc T19; JobPosting/sitemap thuộc T20; cờ gỡ riêng thuộc T43.

Giả định tin lịch sử đã từng đăng, công ty còn xác minh/chưa xóa; chưa tới ngày đăng trả 404. Paused đã quá hạn trình bày hết hạn. Gợi ý cùng nhóm, ưu tiên tỉnh, tối đa 3; không fallback sang nhóm không liên quan. Slug giữ khi sửa tiêu đề theo T10; fixture thay slug để test 301, không thêm công cụ đổi slug.

Đề xuất tiếp theo T19 dùng view con tên công ty cho link ở mọi trạng thái. T24 đọc/khóa bản ghi mới qua scope và gọi hàm chung trước lưu thay vì tin vào HTML cũ. Dừng chờ người dùng xem lại, chưa làm T19.
