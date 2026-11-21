# T13 — Hàng chờ duyệt tin

## Đã làm

- `/app/duyet-tin` chỉ cho admin đang hoạt động, chỉ liệt kê pending. Xem trước toàn bộ tin và lý do quét theo trường; URL ngoài hiển thị cụ thể, dạng văn bản đã thoát ký tự, không tự mở URL.
- `ReviewJob` kiểm tra quyền qua `JobPolicy`, khóa/đọc lại tin trong transaction. Duyệt yêu cầu hạn còn hiệu lực và dữ liệu hợp lệ; từ chối bắt buộc lý do văn bản không rỗng, tối đa 2.000 ký tự, trả pending về draft theo máy trạng thái.
- Migration mới cho `job_reviews` và `notifications` (JSONB tương thích PostgreSQL), không sửa migration của task trước. Lưu quyết định, lý do, người duyệt và thời điểm UTC; giữ lịch sử qua lần sửa/gửi lại. Quan hệ duyệt gần nhất không có trong JSON mặc định của Job.
- Thông báo trong chuông Filament cho tất cả staff đang phụ trách và còn hoạt động. Chỉ lưu thông điệp chung và link kiểm tra lại quyền, không sao chép nội dung hoặc lý do từ chối. Ghi đồng bộ trong cùng transaction, không cần SMTP/worker. Lỗi ghi thông báo hoàn tác cả quyết định và trạng thái.
- Nút Gửi đăng trên trang xem trước/menu tin nối T12, chọn hạn tin, hiện kết quả published/pending và cập nhật ngay trang xem trước. Tin cũ thiếu địa chỉ được nhắc bổ sung; duyệt tin thiếu dữ liệu báo lỗi rõ.
- Giữ thao tác nhân viên rút pending về draft theo quyền redraft đã có; không coi đó là quyết định từ chối của admin.

## Tiêu chí đạt và bằng chứng

| Tiêu chí | Test |
| --- | --- |
| Chỉ admin được quyết định; staff/employer nhận 403 | `denies review queue and decisions to staff and employers with 403`; `denies direct review service calls from assigned staff and employers` |
| Quyền được kiểm tra lại | `rechecks a revoked admin role or locked account for every review decision`; `denies an approval after the admin role is revoked while its modal is open` |
| Hàng chờ chỉ pending, lý do/URL đầy đủ, không thực thi HTML | `shows only pending jobs and escaped exact scan reasons to admins`; `escapes outside URLs in the review queue and preview modal` |
| Duyệt đúng trạng thái, hạn và người duyệt | `publishes held content only on admin approval and records the decision`; `does not publish or notify on invalid approval expiry` |
| Từ chối phải có lý do, trở về draft không có ngày đăng | `requires a nonblank bounded plain text reason when rejecting`; `returns rejected jobs to draft with no publication dates and a reason visible to assigned staff` |
| Đúng người nhận thông báo, không chép nội dung | `notifies only currently assigned active staff without copying content or rejection reasons` |
| Không xử lý lặp; lịch sử còn khi gửi lại | `keeps review history across resubmission and denies duplicate or stale decisions` |
| Giao dịch nguyên tử | `rolls back status and review when database notification persistence fails` |
| UI duyệt/từ chối và gửi đăng | `approves and rejects through the admin queue with required action forms`; `submits clean and held jobs from the management interface` |
| Không đăng khi dữ liệu công ty/địa chỉ thiếu | `keeps a pending job unpublished if its company verification was revoked`; `warns the admin when a legacy held job is missing its work address` |

Các test nằm trong `tests/Feature/JobReviewAuthorizationTest.php` và `tests/Feature/JobReviewTest.php`. Đã viết test từ chối 403 trước phần cho phép; đã thêm assertion đỏ tái hiện trạng thái preview cũ rồi sửa bằng refresh bản ghi sau gửi đăng. Test round-trip migration T10 được cập nhật để rollback bảng con T13 trước bảng cha, đúng thứ tự migration thực tế; không đổi migration T10.

Kết quả local ngày 21/11/2026: `composer test` **335 passed, 1123 assertions**; `composer lint` (Pint/Larastan) xanh. Migration T13 đã chạy trên DB demo bằng `php artisan migrate`, không reset dữ liệu.

Kiểm tra trình duyệt local bằng fixture riêng đã được người dùng cho phép: admin xem trước và lý do Zalo/link ngoài; duyệt một tin, từ chối một tin kèm lý do, hàng chờ không còn hai tin đó. Staff không có menu duyệt, nhận hai thông báo, mở đúng tin và thấy lý do; sửa nội dung, gửi đăng lại tin sạch, trang hiện ngay Đang đăng và ngày đăng/hết hạn. Console không có lỗi. Đã dọn toàn bộ tài khoản, tin, lịch sử và thông báo fixture; giữ nguyên admin/dữ liệu của người dùng.

Ảnh local (Git bỏ qua): `.runtime/t13-queue.jpg`, `.runtime/t13-notifications.jpg`, `.runtime/t13-rejected-preview.jpg`, `.runtime/t13-published-preview.jpg`; `.runtime/t13-console.json` là mảng rỗng. Ảnh chứa dữ liệu giả lập, không phải tin thật.

## Cách kiểm tra

1. Tải lại `/app`; dùng admin hoặc staff đã có 2FA và được phân công công ty đã xác minh.
2. Tạo bản nháp có địa chỉ đầy đủ. Mở xem trước, chọn Gửi đăng và nhập hạn trong tương lai: nội dung sạch sang Đang đăng; nội dung có Zalo hoặc link ngoài sang Chờ duyệt.
3. Admin mở Duyệt tin, xem trước và lý do; chọn Duyệt đăng hoặc Từ chối. Từ chối để trống lý do phải bị chặn.
4. Staff mở chuông thông báo (polling 30 giây hoặc tải lại trang), mở tin bị từ chối, xem lý do, sửa rồi gửi lại. Thu hồi phân công thì link cũ không mở được.

## Chưa đạt, giả định và đề xuất

- Trang tuyển dụng công khai chưa có (T14–T18). T13 chứng minh tin bị từ chối là draft với published_at null; chưa thể kiểm thử việc không xuất hiện trên trang công khai thật. Phải thêm test đó ở T15/T18, không xây trước task.
- Giả định “thông báo cho nhân viên phụ trách” là thông báo trong hệ thống, gửi tới mọi staff đang hoạt động và đang được phân công tại lúc quyết định. Chưa gửi email kết quả duyệt; nếu muốn thêm email cần task/phạm vi và kiểm tra giao hàng riêng.
- Chưa kiểm tra trên production. Checklist đã ghi migration trước phục vụ mã mới, quyền/phân công và thông báo thật. Chưa thêm thư viện, broadcast hoặc worker cho T13.
- Đề xuất: khi có trang công khai, thêm test chỉ published còn hạn được hiển thị; không dùng lịch sử duyệt làm điều kiện thay cho trạng thái hiện tại. Task tiếp theo là T14, chờ người dùng xem lại T13.
