# T15 — Danh sách tin công khai

## Đã làm

- `/viec-lam` dùng Blade layout T14, 12 tin mỗi trang. Các liên kết việc làm trên trang chủ/header/footer đi tới danh sách thật; không dùng tin giả cố định trong mã sản phẩm.
- `Job::publiclyVisible()` lọc status published, published_at không ở tương lai, expires_at lớn hơn thời điểm truy vấn, không lấy tin xóa mềm hoặc công ty đã xóa/chưa đủ xác minh. Tin vừa hết hạn bị loại ngay, không phụ thuộc scheduler T42.
- `ListPublicJobs` sắp xếp published_at DESC rồi id DESC, chỉ lấy cột công khai và eager load company/category/location; không tải nội dung mô tả liên hệ, người tạo, metadata quét hoặc quan hệ duyệt. Không dùng status/company_id từ URL để mở rộng dữ liệu.
- Thẻ tin gồm vị trí, công ty/loại công ty, nhóm vị trí, lương nguyên VND (gồm thỏa thuận/chưa công bố/khoảng một đầu), hình thức, kinh nghiệm, địa chỉ làm việc cụ thể và ngày theo Asia/Ho_Chi_Minh. Không suy ra địa chỉ của tin cũ từ trụ sở công ty.
- Có trạng thái trống, trang ngoài phạm vi có đường về trang đầu, tham số page không hợp lệ quay về trang 1; phân trang tiếng Việt, vùng bấm đủ lớn, không cần JavaScript.
- Không thêm thư viện hoặc migration; không thêm filter/search/chi tiết/ứng tuyển trước task.

## Tiêu chí đạt và tên test

Trong `tests/Feature/PublicJobListTest.php`:

| Tiêu chí | Test |
| --- | --- |
| Loại draft/pending/paused/closed/expired và published quá hạn | `excludes every nonpublic status and expired published jobs from the public list` |
| Không trùng/sót khi cùng ngày/giờ đăng | `paginates equal publication timestamps without duplicate or missing jobs` (27 tin, 3 trang) |
| Ngày đăng trước, ID phá hòa | `sorts publication time before using descending id to break ties` |
| Hết hạn đúng thời điểm, chưa tới ngày đăng | `excludes jobs exactly at expiry and scheduled in the future without waiting for the scheduler` |
| Dữ liệu bị xóa hoặc công ty không còn xác minh | `excludes deleted jobs and jobs from deleted or unverified companies` |
| Bổ sung bằng chứng T13: tin bị từ chối không hiện công khai | `never exposes rejected or pending jobs through supplied status or company parameters` |
| XSS và metadata/nội dung nội bộ không được tải/trả ra | `escapes job cards and does not load or render internal content or scan metadata` |
| Địa chỉ đầy đủ và ngày Việt Nam | `renders complete workplace information and Vietnamese dates on public cards` |
| Tin cũ thiếu địa chỉ | `labels missing legacy work addresses without guessing them from the company office` |
| Rỗng, trang sai và ngoài phạm vi | `renders a useful empty state and handles invalid or out of range pages` |
| Tránh N+1 | `eager loads card data with a bounded number of queries as the page fills` (tối đa 5 truy vấn cho 12 tin) |

`tests/Unit/JobPresentationTest.php`: `formats salary without assuming missing amounts or a payment period` với 8 trường hợp lương, gồm biên PHP_INT_MAX để kiểm tra giữ nguyên số nguyên VND.

Kết quả local ngày 21/11/2026: lần chạy toàn bộ `composer test` **358 passed, 1218 assertions**; sau đó bổ sung kiểm tra biên PHP_INT_MAX và chạy riêng cả 8 trường hợp formatter, đều xanh. `composer lint` (Pint/Larastan) và `npm run build` xanh. T14 có PR riêng và CI xanh trước khi hoàn tất T15. Không chạy lại trình duyệt khi chỉ thêm test, theo yêu cầu người dùng.

Trình duyệt chỉ kiểm tra phần mới/bị ảnh hưởng: CTA trang chủ đi tới `/viec-lam`; danh sách và phân trang ở 360 × 800 không cuộn ngang (scrollWidth bằng clientWidth), địa chỉ dài/tên công ty dài tự xuống dòng. Fixture 15 tin: trang đầu 12, trang sau 3, không giao nhau; quay về trang trước đúng 12 ID ban đầu. Đã xem bố cục desktop, trạng thái trống sau dọn dữ liệu, console không lỗi. Không kiểm tra lại các luồng quản trị không thay đổi.

Ảnh local bị Git bỏ qua: `.runtime/t15-mobile.jpg`, `.runtime/t15-pagination.jpg`, `.runtime/t15-desktop.jpg`, `.runtime/t15-empty.jpg`; `.runtime/t15-console.json` rỗng. Fixture gồm một công ty/15 tin đã được dọn, không tạo tài khoản hoặc đổi quyền/mật khẩu; giữ dữ liệu người dùng. Sau dọn, DB demo hiện không có tin published còn hạn nên danh sách trống là đúng.

## Chưa đạt, giả định và đề xuất

- Không còn tiêu chí T15 chưa đạt trong local; chưa kiểm tra điện thoại thật/production.
- Giả định 12 tin/trang và ưu tiên ngày đăng mới nhất. Thứ tự có ID đảm bảo không trùng/sót do hòa ngày đăng trên tập dữ liệu cố định. Phân trang page theo offset không phải ảnh chụp dữ liệu bất biến khi có người thêm/xóa/đổi trạng thái tin giữa hai lần tải; chưa triển khai cursor/snapshot ngoài phạm vi.
- Chưa có bộ lọc T16, tìm kiếm T17, trang chi tiết T18, trang công ty T19 hoặc form nhận hồ sơ. Vì chưa có trang chi tiết nên tiêu đề thẻ là văn bản, không tạo link/nút chết.
- Đề xuất: tiếp tục T16 khi người dùng duyệt; T18 dùng cùng địa chỉ đầy đủ và thêm kiểm tra theo trạng thái chi tiết. Chưa bổ sung gói đẩy tin T40/T41 hoặc dữ liệu JobPosting T20.
