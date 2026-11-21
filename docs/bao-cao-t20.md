# T20 — Meta, Open Graph, JobPosting và sitemap

## Đã làm

- Meta description/canonical và Open Graph phía server cho trang công khai; chi tiết có tiêu đề, công ty, lương, địa điểm/trạng thái. Closed chỉ dùng thông tin tối thiểu, không lộ lương hoặc nội dung qua head. Canonical bỏ nguồn theo dõi trên chi tiết, giữ bộ lọc đã xác thực và trang phân trang; URL SEO từ APP_URL.
- Ảnh thương hiệu PNG 1200×630 đã commit, không cần package/runtime tạo ảnh. Có script .NET có sẵn trên Windows để tái tạo khi thay thiết kế.
- JobPosting tạo JSON-LD từ tin còn nhận qua hàm chung T18, đủ địa chỉ, description HTML đã lọc gồm mô tả/yêu cầu/quyền lợi, ngày UTC ISO8601 và loại việc làm. JSON_HEX_* + nonce Laravel; không đổi CSP. Không xuất dữ liệu nội bộ, không đoán kỳ lương/mã bưu chính.
- Mặc định `SEO_JOB_POSTING_ENABLED=false`: T22–T24 chưa có cách ứng tuyển thật. Chỉ bật sau khi luồng hoàn chỉnh; test bật cấu hình riêng để kiểm tra bộ tạo. Không làm trước form/POST hoặc sửa nghiệp vụ nhận hồ sơ.
- `/sitemap.xml` là sitemap index; pages, jobs, companies chia 1.000 URL/file, XMLWriter UTF-8 và lastmod từ bản ghi; no-store, đọc trạng thái mới mỗi lần. Jobs qua public scope, loại draft/pending/paused/expired/closed, quá hạn theo đồng hồ, chưa tới ngày đăng, xóa mềm, công ty chưa xác minh/xóa. Robots.txt động khai báo sitemap; phải bỏ file robots.txt tĩnh cũ khi triển khai.
- README, CODEMAP và checklist production có cấu hình, phụ thuộc và bước kiểm tra còn chờ. Không thêm package/migration; không thay tài khoản.

## Tiêu chí đạt và test

`tests/Feature/PublicSeoTest.php`:

- `does not publish JobPosting by default before the application journey is implemented`.
- `never emits JobPosting or salary metadata for closed and no JobPosting for other nonreceiving states` (paused/expired/closed).
- `writes canonical and Open Graph metadata with title salary workplace and an actual preview image`.
- `exports safe complete JobPosting JSON with a matching CSP nonce when explicitly enabled`.
- `omits JobPosting for incomplete legacy workplace and at the exact expiry boundary`.
- `serves an XML sitemap index and only open job URLs refreshing after state and verification changes`.
- `includes only verified company URLs and static public pages and advertises the sitemap in robots`.
- `splits large job sitemaps without duplicate URLs and preserves real modification dates` (1.001 tin, hai file).
- `preserves validated search and pagination in canonical URLs but drops tracking parameters`.

Ngày 21/11/2026: **450 test, 1599 assertions**, `composer lint` (Pint/Larastan), `npm run build`, `git diff --check` xanh. Chỉ test trình duyệt phần mới; không lặp luồng quản trị/xác thực không thay đổi.

Google Rich Results chạy ở chế độ **Mã**, HTML được render từ dữ liệu thử local; URL SEO trong mẫu là example.org, không phải URL đã triển khai. Kết quả **1 JobPosting hợp lệ**, không lỗi bắt buộc; 2 vấn đề không nghiêm trọng: `baseSalary` và `postalCode` chưa có (không bắt buộc). Không tự điền dữ liệu để xóa cảnh báo. [Kết quả kiểm tra](https://search.google.com/test/rich-results/result/r%2Fjobs?id=JwYNusGcfZbC3HjTA3GQzg); ảnh local `.runtime/t20-google-rich-results.jpg`, mẫu `.runtime/t20-rich-results.html`. Ảnh OG đã xem và xác nhận không lỗi chữ/cắt nội dung. Fixture công ty/tin thử T19–T20 được dọn sau kiểm tra, tài khoản giữ nguyên.

Nguồn đối chiếu: [Google JobPosting](https://developers.google.com/search/docs/appearance/structured-data/job-posting), [Open Graph](https://ogp.me/), [Sitemap protocol](https://www.sitemaps.org/protocol.html). Google yêu cầu có cách ứng tuyển thực sự; pass kiểm tra cấu trúc không chứng minh toàn bộ chính sách hoặc khả năng crawl/index.

## Tiêu chí chưa đạt

**Chưa chốt T20 hoàn tất toàn bộ:** chưa xem thẻ preview Zalo từ tên miền HTTPS công khai; localhost không cho crawler bên ngoài truy cập. Chưa kiểm tra Google bằng URL triển khai thật, proxy/CDN hoặc ảnh từ thiết bị/mạng khác. PR giữ draft để phân biệt phần mã/test đã đạt với kiểm chứng tích hợp còn chờ. Các bước đã ghi rõ trong checklist production, không đánh dấu đạt giả.

JobPosting mặc định chưa xuất ở trang chạy thật cho tới khi có luồng ứng tuyển T22–T24; bộ tạo đã hoàn tất và được Google kiểm tra trên mẫu. Không tự triển khai website, gửi tin nhắn Zalo hoặc làm T21/T22 để vượt phụ thuộc.

## Giả định đã dùng

- Ảnh OG thương hiệu dùng chung; tiêu đề/lương/địa điểm riêng từng tin nằm trong meta. Closed không xuất lương kể cả khi có cấu hình JobPosting bật.
- Hệ thống chưa lưu kỳ tính lương hoặc mã bưu chính. Giữ hai thuộc tính tùy chọn trống; không giả định lương/tháng, không thêm trường ngoài phạm vi.
- APP_URL là URL gốc chính thức, được quản trị cấu hình đúng. Sitemap dùng ngày sửa bản ghi, không lấy ngày tạo sitemap làm lastmod.

## Đề xuất thêm

Khi có môi trường HTTPS, kiểm tra thẻ Zalo bằng link published/closed và tải ảnh thật, rồi xem xét chuyển PR khỏi draft. Sau T24, kiểm tra nhận hồ sơ thực tế trước bật JobPosting và chạy Rich Results bằng URL thật. Nếu cần lương có cấu trúc sau này, bổ sung kỳ lương theo task được duyệt. Dừng tại T20, không tự làm T21.
