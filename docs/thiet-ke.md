# Thiết kế hệ thống website tuyển dụng

Bản chuyển từ tài liệu Word, cập nhật theo các quyết định người dùng chốt ngày 20/11/2026. Mục 11 có ưu tiên khi các phần đề xuất trước đó khác với quyết định đã chốt.

Tài liệu thiết kế kỹ thuật, phiên bản 1, ngày 20/11/2026

## 1. Tổng quan

Mục tiêu. Website đăng tin tuyển dụng cho nhiều công ty. Ứng viên ứng tuyển trực tiếp trên website, không cần đăng nhập. Nhà tuyển dụng muốn xem chi tiết hồ sơ phải trả phí, và admin cấp quyền sau khi xác nhận thanh toán. Zalo chỉ dùng để dán link tin tuyển dụng, không tích hợp API.

Nguyên tắc thiết kế

Mọi kiểm tra quyền thực hiện ở server. Giao diện chỉ phản ánh kết quả, không phải nơi bảo vệ dữ liệu.

Server chỉ gửi về trình duyệt đúng những gì người dùng được phép thấy. Chưa có quyền thì SĐT, email, link CV không được xuất hiện trong dữ liệu trả về.

Mọi thao tác nhạy cảm (xem, tải, cấp quyền, thu hồi) đều ghi nhật ký.

Dùng thành phần mã nguồn mở, phổ biến, dễ thuê người bảo trì.

Các vai trò

| Vai trò | Quyền chính |
| --- | --- |
| Admin | Toàn quyền: nhân viên, công ty, gói, thanh toán, cấp và thu hồi quyền xem hồ sơ, xem nhật ký |
| Nhân viên | Thêm công ty, vị trí, đăng và sửa tin của công ty mình phụ trách, tạo tài khoản cho công ty, xem hồ sơ theo phân công |
| Tài khoản công ty (nhà tuyển dụng) | Xem danh sách ứng viên của công ty mình. Xem chi tiết CV chỉ khi có quyền còn hiệu lực |
| Ứng viên (khách) | Xem tin, lọc, gửi hồ sơ. Không có tài khoản |

## 2. Công nghệ đề xuất

Tất cả phần mềm dưới đây là mã nguồn mở và miễn phí. Chi phí thực tế chỉ gồm VPS và tên miền.

| Lớp | Lựa chọn | Lý do |
| --- | --- | --- |
| Ngôn ngữ, framework | PHP 8.3 trở lên và Laravel bản ổn định mới nhất | Có sẵn đăng nhập, phân quyền, kiểm tra dữ liệu, chống CSRF, hàng đợi, lịch chạy tự động. Cộng đồng lớn, dễ nâng cấp từng phiên bản |
| Trang quản trị | Filament | Dựng bảng, form, lọc, phân quyền nhanh trên nền Laravel |
| Giao diện công khai | Blade, Tailwind CSS, Alpine.js | Hiển thị phía server nên tốt cho SEO và mở nhanh trên điện thoại |
| Database chính | PostgreSQL | Miễn phí, chặt chẽ về ràng buộc dữ liệu, có sẵn tìm kiếm không dấu (unaccent) và tìm gần đúng (pg_trgm) |
| Hàng đợi, cache | Bắt đầu bằng database queue. Khi tải tăng thì thêm Redis | Giảm thành phần cần vận hành lúc đầu |
| Lưu file | Thư mục riêng tư ngoài web root, truy cập qua Laravel Storage | Về sau chuyển sang kho kiểu S3 chỉ cần đổi cấu hình |
| Xem trước CV Word | LibreOffice chạy nền để đổi DOC/DOCX sang PDF | Trình duyệt xem PDF được, không xem Word được |
| Quét virus | ClamAV | Miễn phí, quét file trước khi cho phép xem |
| Chống spam form | Cloudflare Turnstile hoặc hCaptcha | Có gói miễn phí |
| Phân quyền | Laravel Policies và gói spatie/laravel-permission | Chuẩn, dễ kiểm thử |
| Kiểm thử | Pest hoặc PHPUnit | Viết test bắt buộc cho phần phân quyền |
| Web server | Nginx, PHP-FPM, Let's Encrypt (HTTPS miễn phí) | Ổn định, phổ biến |

Phương án thay thế: Next.js kết hợp Supabase cũng làm được và có sẵn đăng nhập, lưu file. Mình chọn Laravel vì ít thành phần rời rạc hơn, dễ kiểm soát phân quyền ở server, và một người có thể vận hành toàn bộ.

## 3. Database

Hệ thống dùng hai nơi lưu trữ:

PostgreSQL: toàn bộ dữ liệu có cấu trúc (công ty, tin, hồ sơ, quyền, nhật ký).

Kho file riêng tư: file CV và file portfolio. Database chỉ lưu đường dẫn và thông tin file.

### 3.1. Các bảng chính

users: id, tên, email, mật khẩu (đã mã hóa), vai trò, trạng thái (hoạt động, khóa), 2FA, lần đăng nhập cuối.

companies: id, tên, loại (direct công ty chủ việc, agency công ty trung gian), mã số thuế, địa chỉ, logo, giới thiệu, ngành hàng và sản phẩm kinh doanh, trạng thái xác minh, nhân viên phụ trách.

company_channels: id, company_id, loại (Shopee, TikTok Shop, Lazada, YouTube, website…), tên hiển thị, URL.

company_user: user_id, company_id. Cho biết tài khoản công ty nào thuộc công ty nào.

staff_company: user_id (nhân viên), company_id. Cho biết nhân viên phụ trách công ty nào.

locations: tỉnh/thành, quận/huyện.

job_categories: nhóm vị trí (kinh doanh, kho vận, marketing, livestream, CSKH…).

jobs: id, company_id, category_id, location_id, tiêu đề, slug, mô tả, yêu cầu, quyền lợi, lương tối thiểu, lương tối đa, cờ "thỏa thuận", yêu cầu kinh nghiệm (số năm), số lượng cần tuyển, hình thức (toàn thời gian, bán thời gian), trạng thái (draft, pending, published, expired, closed), published_at, expires_at, created_by, thời điểm xóa mềm.

plans: id, tên gói (ví dụ 3 ngày, 7 ngày), số ngày, mức ưu tiên, giá.

job_boosts: id, job_id, plan_id, starts_at, ends_at, priority, order_id.

applications: id (ULID công khai), job_id, company_id (lưu thêm để lọc nhanh), họ tên, SĐT (đã chuẩn hóa), email (tùy chọn), cv_file_id, portfolio_url, portfolio_file_id, đã đồng ý xử lý dữ liệu (thời điểm), nguồn (?nguon=), submitted_at, trạng thái (new, viewed, interview, hired, rejected), ghi chú nội bộ, thời điểm xóa mềm.

files: id, đường dẫn lưu, tên gốc, MIME đã kiểm tra, dung lượng, mã băm SHA-256, trạng thái quét (pending, clean, infected), đường dẫn bản PDF xem trước.

access_grants: id, company_id, job_id (bắt buộc, phạm vi theo tin), starts_at, ends_at, trạng thái (active, revoked), order_id, cấp bởi (user), ghi chú. Luôn áp dụng cho hồ sơ nộp trước ngày mua. Chưa triển khai quyền theo công ty hoặc theo lượt xem.

orders: id, company_id, loại (quyền xem hồ sơ hoặc gói đẩy tin), số tiền, trạng thái (pending, paid, cancelled), phương thức, mã giao dịch, người xác nhận, thời điểm xác nhận.

audit_logs: id, user_id, hành động, đối tượng (loại và id), IP, user agent, thời điểm. Không lưu nội dung CV hay SĐT trong nhật ký.

reports: id, job_id, lý do, nội dung, thời điểm, trạng thái xử lý.

### 3.2. Ràng buộc và chỉ mục quan trọng

Khóa ngoại cho mọi quan hệ. Thao tác quản lý tin và hồ sơ thường dùng xóa mềm. Riêng hồ sơ đủ 6 tháng hoặc xóa thủ công hợp lệ bởi admin phải xóa thông tin cá nhân và file; chỉ giữ thống kê không định danh.

Ràng buộc: lương_tối_thiểu <= lương_tối_đa, ends_at > starts_at ở cả access_grants và job_boosts.

Duy nhất theo (job_id, SĐT đã chuẩn hóa) trong hồ sơ còn hiệu lực, chống nộp trùng.

Chỉ mục: jobs(status, published_at), jobs(location_id, category_id), jobs(lương), applications(company_id, job_id, submitted_at), access_grants(company_id, status, ends_at).

Thời gian lưu dạng UTC, hiển thị theo múi giờ Asia/Ho_Chi_Minh.

## 4. Luồng xử lý

### 4.1. Nhân viên tạo công ty và đăng tin

Nhân viên đăng nhập. Server kiểm tra vai trò và bắt buộc 2FA cho admin, nhân viên.

Nhân viên tạo công ty. Hệ thống ghi vào companies, company_channels và gán công ty cho nhân viên đó trong staff_company.

Nhân viên tạo vị trí, điền theo form có sẵn: địa điểm, lương, kinh nghiệm, mô tả. Tin ở trạng thái draft.

Khi bấm đăng, hệ thống quét nội dung tìm SĐT, email, Zalo, link lạ. Nếu sạch thì sang published. Nếu nghi ngờ thì sang pending để duyệt tay.

Tin đã đăng có published_at và expires_at. Link công khai dạng /viec-lam/ten-vi-tri-123.

### 4.2. Tạo tài khoản cho nhà tuyển dụng

Nhân viên chọn công ty, nhập email người đại diện. Hệ thống tạo users với vai trò công ty, thêm dòng vào company_user.

Hệ thống gửi email mời đặt mật khẩu qua link dùng một lần, hết hạn sau 24 giờ. Không gửi mật khẩu qua email hay Zalo.

### 4.3. Ứng viên xem tin và ứng tuyển

Ứng viên mở link từ Zalo. Trang tin hiển thị phía server.

Ứng viên điền form: họ tên, SĐT, (email), CV, portfolio (link hoặc file), tích đồng ý xử lý dữ liệu, qua captcha.

Server kiểm tra dữ liệu, chuẩn hóa SĐT, kiểm tra trùng.

Server nhận file vào thư mục tạm, kiểm tra loại thật của file và dung lượng.

Trong một giao dịch database: tạo bản ghi files, tạo applications. Nếu lỗi thì hoàn tác và xóa file tạm.

Hệ thống đưa file vào hàng đợi: quét virus, đổi Word sang PDF xem trước. Hồ sơ đã nhận được hiển thị cho ứng viên ngay mà không cần chờ bước này.

Hệ thống gửi email tóm tắt cho nhà tuyển dụng: "Có 1 hồ sơ mới ở vị trí X", không kèm thông tin ứng viên.

### 4.4. Nhà tuyển dụng chưa có quyền

Đăng nhập, server xác định công ty của tài khoản.

Truy vấn hồ sơ luôn lọc theo company_id của tài khoản, không tin bất kỳ company_id nào gửi từ trình duyệt.

Trả về: họ tên, vị trí ứng tuyển, thời gian ứng tuyển. Không trả SĐT, email, link CV, portfolio.

Giao diện hiện nút "Yêu cầu mở hồ sơ" cạnh mỗi tin.

### 4.5. Mua quyền xem và admin cấp quyền

Nhà tuyển dụng bấm yêu cầu. Hệ thống tạo orders trạng thái pending và hiển thị hướng dẫn thanh toán.

Admin đối chiếu thanh toán, bấm xác nhận. Hệ thống cập nhật orders thành paid và tạo access_grants với ngày bắt đầu, kết thúc, phạm vi.

Hai thao tác này nằm trong một giao dịch database để không xảy ra tình trạng đã thu tiền mà chưa cấp quyền, hoặc ngược lại.

Hệ thống ghi nhật ký người cấp quyền. Có thể gửi email báo cho nhà tuyển dụng.

### 4.6. Xem và tải CV khi đã có quyền

Nhà tuyển dụng bấm vào hồ sơ. Server chạy policy: tài khoản thuộc công ty của hồ sơ, tài khoản còn hoạt động, tồn tại quyền hợp lệ (đúng phạm vi, status = active, đang trong khoảng starts_at đến ends_at).

Nếu qua: hiện SĐT, email, ghi chú, trạng thái. File CV được phục vụ qua một đường dẫn của server (xem mục 5), mỗi lần bấm đều kiểm tra lại quyền.

Xem trực tiếp: file PDF hiển thị trong trang. Tải xuống: server gửi file với tiêu đề tải về.

Mỗi lần xem và tải đều ghi audit_logs.

Nếu không qua: trả về lỗi 403, không tiết lộ hồ sơ có tồn tại hay không.

### 4.7. Gói đẩy tin

Admin hoặc nhân viên chọn tin, chọn gói, ghi nhận đơn hàng. Hệ thống tạo job_boosts với starts_at và ends_at.

Khi hiển thị danh sách, thứ tự được tính bằng truy vấn: tin có gói đang hiệu lực xếp trước (mức ưu tiên cao hơn trước), sau đó theo published_at mới nhất, cuối cùng theo id để thứ tự ổn định.

Hết hạn gói tự trở về thứ tự thường vì điều kiện thời gian nằm trong truy vấn, không phụ thuộc việc lịch chạy có kịp hay không.

### 4.8. Hết hạn tự động

Mỗi đêm một lịch chạy: chuyển tin quá hạn sang expired, gửi nhắc gia hạn trước 3 ngày, đánh dấu quyền xem hết hạn, xóa file tạm, và xóa hồ sơ quá thời gian lưu trữ theo chính sách (xem mục 6).

## 5. Lưu trữ CV và portfolio

### 5.1. Quy định file

| Loại | Định dạng cho phép | Dung lượng tối đa |
| --- | --- | --- |
| CV | PDF, DOC, DOCX | 10 MB |
| Portfolio dạng file | PDF, JPG, PNG | 10 MB |
| Portfolio dạng link | URL bắt đầu bằng http hoặc https | Không áp dụng |

Không nhận file nén, file chạy được, hoặc file có macro.

### 5.2. Xử lý khi upload

Kiểm tra loại thật của file bằng nội dung, không chỉ dựa vào đuôi file hay loại do trình duyệt khai báo.

Đổi tên file thành chuỗi ngẫu nhiên (UUID). Tên gốc chỉ lưu trong database để hiển thị.

Lưu ngoài thư mục web, không bao giờ có đường dẫn trực tiếp từ internet.

Cấu trúc thư mục: cv/{công-ty}/{vị-trí}/{mã-hồ-sơ}/, thuận tiện quản lý và xóa theo công ty hoặc vị trí.

Giới hạn dung lượng được kiểm tra ở cả Nginx, PHP và Laravel để người dùng nhận thông báo rõ thay vì lỗi trắng trang.

Quét virus trước khi cho phép xem. File chưa quét xong hiển thị trạng thái "đang xử lý" cho nhà tuyển dụng.

Với DOC/DOCX: tạo thêm bản PDF để xem trong trình duyệt. Giữ bản gốc để tải xuống.

### 5.3. Xem và tải

File được gửi qua một đường dẫn có kiểm tra quyền mỗi lần, đọc dạng luồng để không tốn bộ nhớ với file lớn.

Có thể dùng link ký tên (signed URL) hết hạn sau vài phút. Link bị chia sẻ cũng hết hiệu lực nhanh.

Chế độ xem: gửi inline. Chế độ tải: gửi attachment.

Đặt tiêu đề X-Content-Type-Options: nosniff để trình duyệt không đoán sai loại file.

### 5.4. Portfolio dạng link

Chỉ chấp nhận http và https. Chặn javascript: và các giao thức khác.

Hiển thị bằng liên kết mở tab mới với rel="noopener noreferrer". Không nhúng trực tiếp vào trang (iframe) và không để server tự mở link đó.

Có dòng cảnh báo: "Liên kết bên ngoài, hãy cẩn thận khi mở".

Link portfolio cũng chỉ hiển thị khi có quyền, giống CV.

## 6. Bảo mật và phân quyền

### 6.1. Bảng quyền xem

| Dữ liệu | Admin | Nhân viên phụ trách | Công ty chưa có quyền | Công ty có quyền |
| --- | --- | --- | --- | --- |
| Họ tên, vị trí, thời gian ứng tuyển | Có | Có | Có | Có |
| SĐT, email | Có | Có | Không | Có |
| CV, portfolio (xem và tải) | Có | Có | Không | Có |
| Ghi chú nội bộ, đổi trạng thái | Có | Có | Không | Có (chỉ phần của công ty mình) |

Công ty chỉ thấy hồ sơ có company_id của chính mình. Nhân viên chỉ thấy công ty được phân công.

### 6.2. Biện pháp kỹ thuật

Đăng nhập: mật khẩu băm bằng Argon2 hoặc bcrypt, giới hạn số lần thử, khóa tạm khi sai nhiều lần. Bắt buộc 2FA cho admin và nhân viên.

Phiên làm việc: cookie Secure, HttpOnly, SameSite. Đổi mật khẩu hoặc thu hồi tài khoản thì đăng xuất mọi thiết bị.

Chống tấn công thường gặp: CSRF (Laravel có sẵn), SQL injection (dùng truy vấn tham số hóa của Laravel, không nối chuỗi), XSS (thoát ký tự khi hiển thị, nội dung rich text phải lọc bằng HTMLPurifier), clickjacking (X-Frame-Options), và tiêu đề Content-Security-Policy.

IDOR: mã hồ sơ công khai dùng ULID hoặc UUID thay vì số tăng dần. Quan trọng hơn, mỗi truy cập đều qua policy, nên đổi mã trên link cũng không xem được hồ sơ công ty khác.

Kiểm tra quyền tại thời điểm truy cập, không lưu quyền trong phiên. Admin thu hồi thì hiệu lực ngay lập tức.

Nhật ký: ai xem hồ sơ nào, lúc nào, từ IP nào. Cảnh báo khi một tài khoản xem hoặc tải số lượng lớn trong thời gian ngắn.

Giới hạn thiết bị: hạn chế số phiên đăng nhập cùng lúc của một tài khoản công ty, chống dùng chung tài khoản.

Hình mờ: có thể thêm hình mờ tên công ty lên bản xem trước CV để khó phát tán.

Máy chủ: tường lửa (UFW), fail2ban, cập nhật bản vá định kỳ, tắt đăng nhập SSH bằng mật khẩu, chỉ dùng khóa SSH.

Backup: sao lưu database và file hằng ngày, mã hóa, lưu ở nơi khác máy chủ chính, định kỳ thử khôi phục.

### 6.3. Dữ liệu cá nhân

Form ứng tuyển có ô đồng ý ghi rõ hồ sơ sẽ được nhà tuyển dụng đã mua quyền xem.

Tự động xóa thông tin cá nhân và file sau 6 tháng kể từ ngày nộp, chỉ giữ thống kê không định danh. Ứng viên không có chức năng, nút hoặc đường dẫn tự xóa. Chỉ admin có công cụ xóa thủ công cho trường hợp pháp lý hợp lệ (T46).

Điều khoản dành cho nhà tuyển dụng: chỉ dùng hồ sơ cho tuyển dụng, không bán lại, không chia sẻ tài khoản.

Nên hỏi luật sư về quy định bảo vệ dữ liệu cá nhân hiện hành của Việt Nam trước khi mở chính thức.

## 7. Chi tiết chức năng

### 7.1. Trang công khai

Danh sách tin với lọc: địa điểm, vị trí, mức lương, kinh nghiệm, loại công ty (chủ việc hoặc trung gian), từ khóa tìm không dấu.

Đường dẫn lưu bộ lọc, ví dụ /viec-lam?dia-diem=hcm&luong=15&kinh-nghiem=0&trang=2. Bấm quay lại sẽ về đúng trang và bộ lọc.

Thẻ tin: vị trí, công ty, lương, địa điểm, nhãn "Nổi bật" cho tin đang đẩy.

Trang chi tiết: mô tả, thông tin công ty, link các kênh bán hàng và video, form ứng tuyển, nút chia sẻ.

Trang công ty: giới thiệu, ngành hàng, các tin đang tuyển.

Ảnh xem trước khi dán link vào Zalo (thẻ Open Graph gồm tiêu đề, mức lương, địa điểm).

Dữ liệu JobPosting cho Google, trang sơ đồ (sitemap) tự cập nhật.

Nút "Báo cáo tin".

### 7.2. Form ứng tuyển

Chỉ yêu cầu họ tên và SĐT. CV có thể tùy chọn tùy cấu hình từng tin, vì một số vị trí không đòi hỏi CV.

Giữ dữ liệu đã nhập khi có lỗi, báo lỗi ngay tại từng ô.

Báo "đã nhận hồ sơ" rõ ràng sau khi gửi và chặn gửi lặp lại bằng nút bấm.

Ghi nguồn từ tham số ?nguon= để biết nhóm Zalo nào hiệu quả.

### 7.3. Trang nhân viên

Quản lý công ty: thông tin, kênh bán hàng, trạng thái xác minh.

Quản lý tin: tạo, sửa, nhân bản, tạm dừng, đóng tin, xem trước.

Tạo và khóa tài khoản công ty, gửi lại email mời.

Danh sách hồ sơ theo công ty, theo vị trí, có tìm kiếm và lọc theo ngày, trạng thái.

Nút sao chép nội dung đăng Zalo kèm link có tham số nguồn.

### 7.4. Trang admin

Tất cả chức năng của nhân viên, cộng thêm: quản lý nhân viên và phân công công ty.

Duyệt tin chờ duyệt và xử lý báo cáo.

Quản lý gói đẩy tin và đơn hàng, xác nhận thanh toán, cấp, gia hạn, thu hồi quyền xem hồ sơ.

Xem nhật ký truy cập và các cảnh báo bất thường.

Thống kê: lượt xem tin, số hồ sơ, doanh thu, nguồn ứng viên.

### 7.5. Trang nhà tuyển dụng

Danh sách tin của công ty và số hồ sơ mỗi tin.

Danh sách hồ sơ: hiện tên, vị trí, thời gian. Có nút mở khóa nếu chưa có quyền.

Khi đã có quyền: xem chi tiết, xem CV và portfolio, tải xuống, đổi trạng thái, ghi chú.

Hiển thị rõ quyền còn bao nhiêu ngày và khi nào hết hạn.

## 8. Các lỗi dễ gặp và cách tránh

| Tình huống | Cách xử lý |
| --- | --- |
| Nhà tuyển dụng đổi mã trên link để xem hồ sơ công ty khác | Policy kiểm tra công ty ở mọi truy vấn. Có test tự động cho trường hợp này |
| Chưa có quyền nhưng SĐT vẫn nằm trong dữ liệu trả về | Không chọn các cột nhạy cảm khi chưa có quyền, không chỉ ẩn bằng giao diện |
| Ứng viên bấm gửi nhiều lần | Khóa nút sau khi bấm, kèm mã chống gửi lặp và ràng buộc duy nhất (tin, SĐT) |
| Upload xong nhưng lỗi giữa chừng, để lại file mồ côi | Dùng giao dịch database, xóa file khi hoàn tác, dọn file tạm định kỳ |
| File sai định dạng hoặc đổi đuôi để lách | Kiểm tra loại thật của file bằng nội dung |
| File lớn làm tràn bộ nhớ khi tải | Đọc và gửi dạng luồng |
| Word không xem được trong trình duyệt | Tạo bản PDF xem trước, giữ bản gốc để tải |
| Quyền hết hạn đúng lúc nhà tuyển dụng đang xem | Kiểm tra quyền mỗi lần tải file, không dựa vào lần kiểm tra trước đó |
| Thanh toán đã xác nhận nhưng cấp quyền thất bại | Hai việc nằm trong một giao dịch, lỗi thì hoàn tác cả hai |
| Admin bấm xác nhận thanh toán hai lần | Kiểm tra trạng thái đơn trước khi xử lý, mỗi đơn chỉ tạo một quyền |
| Lệch múi giờ làm quyền hết hạn sớm hoặc muộn | Lưu UTC, hiển thị giờ Việt Nam, test vào ngày giờ biên |
| Gói đẩy hết hạn nhưng tin vẫn đứng đầu | Điều kiện thời gian nằm trong truy vấn, không dựa vào cờ do lịch chạy cập nhật |
| Danh sách bị lặp hoặc sót tin khi chuyển trang | Thêm id vào cuối quy tắc sắp xếp để thứ tự ổn định |
| Tìm "ke toan" không ra "kế toán" | Dùng unaccent của PostgreSQL và lưu cột tìm kiếm đã bỏ dấu |
| Lương nhập sai (tối thiểu lớn hơn tối đa, số âm) | Kiểm tra ở form và ràng buộc trong database |
| Tin lừa đảo hoặc có SĐT ẩn trong nội dung | Quét nội dung có chuẩn hóa chữ số cách khoảng, duyệt tay tin nghi ngờ, cho phép báo cáo |
| Nhà tuyển dụng nhét SĐT vào ảnh banner | Chỉ cho tải logo hoặc duyệt ảnh trước khi hiển thị |
| Nội dung độc hại (XSS) trong mô tả, tên, ghi chú | Thoát ký tự khi hiển thị, lọc HTML bằng HTMLPurifier |
| Xuất Excel bị chèn công thức độc hại | Thêm dấu ' vào đầu ô bắt đầu bằng =, +, -, @ |
| Xóa công ty hoặc tin làm mất hồ sơ | Xóa mềm, giữ hồ sơ theo chính sách lưu trữ |
| Tài khoản bị khóa nhưng phiên cũ vẫn dùng được | Kiểm tra trạng thái tài khoản ở mọi yêu cầu, xóa phiên khi khóa |
| Nhật ký chứa thông tin cá nhân | Chỉ ghi mã hồ sơ, không ghi SĐT hay nội dung CV |
| Email báo hồ sơ rơi vào thư rác | Cấu hình SPF, DKIM, DMARC cho tên miền và dùng dịch vụ gửi mail chuyên dụng |
| Quay lại trình duyệt bị mất bộ lọc | Bộ lọc là tham số trên URL (GET), không giữ trạng thái chỉ trong JavaScript |
| Tin hết hạn trả về trang lỗi, mất lượt tìm kiếm từ Google | Trang tin hết hạn vẫn mở được, hiển thị "đã hết hạn" kèm gợi ý tin tương tự |
| Truy vấn chậm khi dữ liệu nhiều | Chỉ mục như mục 3.2, tải trước dữ liệu liên quan để tránh truy vấn lặp (N+1), phân trang |
| Lỗi sau khi nâng cấp | Có môi trường thử (staging), migration có kiểm soát, test tự động chạy trước mỗi lần đưa lên máy chủ |

Kiểm thử bắt buộc trước khi mở chính thức

Test phân quyền: mỗi vai trò thử truy cập dữ liệu và file của công ty khác, phải bị từ chối.

Test quyền xem: chưa mua, vừa mua, sắp hết hạn, hết hạn, bị thu hồi.

Test upload: file đúng, file quá 10 MB, file đổi đuôi, file hỏng, mất mạng giữa chừng.

Test trên điện thoại thật, vì phần lớn ứng viên mở link từ Zalo.

Thử khôi phục từ backup ít nhất một lần.

## 9. Triển khai

Môi trường: máy cá nhân (Laragon hoặc tương đương), môi trường thử, máy chủ chính thức.

Máy chủ: VPS Ubuntu LTS, Nginx, PHP-FPM, PostgreSQL, Supervisor để chạy hàng đợi, cron cho lịch chạy hằng ngày, ClamAV, LibreOffice (chế độ không giao diện).

HTTPS: Let's Encrypt, tự gia hạn.

Giám sát: theo dõi hoạt động (uptime), ghi log lỗi, có thể dùng gói miễn phí của Sentry.

Phát hành: dùng Git, có thể tự động kiểm thử rồi mới đưa lên máy chủ.

## 10. Lộ trình

Giai đoạn 1 (lõi): tài khoản và phân quyền, công ty, vị trí, đăng tin, trang công khai có lọc và link riêng, form ứng tuyển, lưu file an toàn, xem hồ sơ theo công ty, chưa có quyền chỉ thấy tên.

Giai đoạn 2 (kiếm tiền): đơn hàng và cấp quyền xem hồ sơ, gói đẩy tin, quét nội dung chống liên hệ ngoài, nhật ký truy cập, email báo hồ sơ mới, nút sao chép nội dung Zalo.

Giai đoạn 3 (mở rộng): thanh toán tự động, thống kê và báo cáo cho công ty, OTP xác thực SĐT ứng viên, kho ứng viên, hình mờ lên CV.

## 11. Các quyết định đã chốt

- Phí xem hồ sơ tính theo vị trí (một tin tuyển dụng). Mỗi lần mua mở toàn bộ hồ sơ của tin trong thời hạn admin chọn.
- Được xem hồ sơ nộp trước ngày mua quyền.
- Hết hạn hoặc thu hồi: chặn ngay lần truy cập kế tiếp khi xem chi tiết, xem file, tải file. Muốn xem lại phải gia hạn.
- Tự động xóa thông tin cá nhân và file sau 6 tháng kể từ ngày nộp. Chỉ giữ số liệu thống kê không có danh tính.
- Ứng viên không có nút, đường dẫn hay chức năng tự xóa. Chỉ admin xóa thủ công cho trường hợp pháp lý hợp lệ (T46).
- Mỗi công ty có trang công khai mở từ tin: tên, loại công ty, địa chỉ, giới thiệu, sản phẩm, link Shopee/TikTok/Lazada và kênh video. Không hiện dữ liệu nội bộ.
- Công ty phải được admin xác minh trước khi đăng tin.
- Ứng viên không có tài khoản, ứng tuyển trực tiếp trên website.
- Xác thực bằng phiên cookie bảo mật Laravel; không dùng JWT, access token hoặc refresh token. Sanctum chỉ cân nhắc khi có app di động/API riêng.
- Thanh toán chuyển khoản, admin xác nhận thủ công. Chưa thanh toán tự động.
- access_grants chỉ dùng phạm vi theo tin; chưa có gói theo công ty hoặc theo số lượt xem.
- Zalo chỉ để dán link tin và ảnh Open Graph; không tích hợp API.
- Chưa có OTP xác thực SĐT ứng viên.
- Website riêng của công ty nhận mọi URL HTTP/HTTPS hợp lệ, không yêu cầu allowlist tên miền. Các kênh nền tảng vẫn kiểm tra đúng tên miền của nền tảng.

## 12. Kế hoạch giao việc cho Codex

Mục tiêu: mỗi lần chỉ giao một việc nhỏ, có tiêu chí hoàn thành và bài kiểm tra rõ ràng. Nhờ vậy không bỏ sót tính năng và lỗi được phát hiện ngay khi còn nhỏ.

### 12.1. Quy tắc làm việc

Một task là một nhánh Git và một pull request. Không gộp nhiều task vào một lần giao.

Mỗi task nên thay đổi tối đa khoảng 300 dòng mã (không tính file sinh tự động). Lớn hơn thì tách nhỏ trước khi giao.

Viết hoặc cập nhật test cùng lúc với mã. Task chỉ xong khi toàn bộ test (cả test cũ) và công cụ kiểm tra mã đều xanh.

Codex chỉ làm đúng phạm vi task. Thấy việc khác cần làm thì ghi vào phần đề xuất cuối báo cáo, không tự làm.

Không sửa migration đã chạy ở task trước. Muốn đổi cấu trúc thì thêm migration mới.

Cuối mỗi task, Codex báo cáo theo mẫu: đã làm gì, tiêu chí nào đạt (kèm tên test), tiêu chí nào chưa đạt, giả định đã dùng, đề xuất thêm.

Bạn hoặc người có kinh nghiệm xem lại diff trước khi gộp. Task đánh dấu ⚠ (phân quyền, file, thanh toán) phải xem kỹ nhất.

Task về phân quyền: viết test từ chối trước (người không có quyền phải nhận 403), rồi mới viết test cho phép.

Cuối mỗi giai đoạn có một cổng kiểm: chạy thử toàn bộ trên môi trường thử, tick danh sách, rồi mới sang giai đoạn sau.

### 12.2. File AGENTS.md (đặt ở thư mục gốc repo)

Codex đọc file này trước mỗi task. Nội dung gợi ý:

Stack: Laravel (PHP 8.3 trở lên), PostgreSQL, Filament, Blade, Tailwind, Alpine.js, Pest.

Lệnh: composer test, composer lint, php artisan migrate:fresh --seed.

Mọi truy vấn hồ sơ ứng viên phải đi qua ApplicationPolicy. Không đọc cột nhạy cảm (SĐT, email, file) ngoài policy.

Không bao giờ ghi SĐT, email, nội dung CV vào log.

Thời gian lưu UTC, hiển thị theo Asia/Ho_Chi_Minh.

Mọi thao tác xem hoặc tải hồ sơ phải ghi audit_logs.

Chỉ làm trong phạm vi task. Không đổi migration cũ. Không thêm thư viện mới nếu chưa được duyệt.

Chuỗi giao diện bằng tiếng Việt, đặt trong file ngôn ngữ.

Lưu bản tài liệu thiết kế này vào docs/thiet-ke.md và đọc mục 3 đến 6 trước khi làm.

### 12.3. Mẫu prompt cho mỗi task

Task T24: Form ứng tuyển.Bối cảnh: đọc AGENTS.md và docs/thiet-ke.md (mục 4.3 và 5).Phạm vi: chỉ làm những việc liệt kê dưới đây, không sửa phần khác.Việc cần làm: ...Tiêu chí hoàn thành: ...Test bắt buộc: ...Không làm: ...Khi xong: chạy composer test và composer lint, rồi báo cáo theo mẫu(đã làm, tiêu chí đạt kèm tên test, chưa đạt, giả định, đề xuất).

Cột "Tiêu chí" trong các bảng dưới đây chính là nội dung điền vào mục tiêu chí và test của prompt.

### 12.4. Giai đoạn 0: nền tảng

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T00 | Khởi tạo dự án Laravel, kết nối PostgreSQL, cài Pest, công cụ kiểm tra mã (Pint, Larastan), file .env.example, README hướng dẫn chạy máy cá nhân, CI chạy test mỗi lần đẩy mã | Clone về chạy được theo README. composer test và composer lint xanh. CI xanh |
| T01 | Cấu hình nền: múi giờ UTC, hiển thị giờ Việt Nam, ngôn ngữ tiếng Việt, ép HTTPS ở môi trường thật, middleware thêm header bảo mật (nosniff, X-Frame-Options, CSP cơ bản), giới hạn tốc độ mặc định | Test kiểm tra các header có mặt. Test múi giờ: lưu 23:30 UTC hiển thị đúng ngày giờ Việt Nam |
| T02 | Migration bảng users kèm vai trò (admin, staff, employer) và trạng thái (active, locked). Seeder tạo 1 admin, 1 nhân viên, 1 tài khoản công ty mẫu | migrate:fresh --seed chạy được. Test model: vai trò và trạng thái chỉ nhận giá trị hợp lệ |
| T03 ⚠ | Đăng nhập, đăng xuất, đặt lại mật khẩu qua link dùng một lần hết hạn 24 giờ, giới hạn số lần thử sai, khóa tài khoản. Kiểm tra trạng thái tài khoản ở mọi yêu cầu. Bắt buộc 2FA cho admin và staff | Test: sai mật khẩu quá giới hạn thì bị chặn. Tài khoản bị khóa thì phiên đang mở bị đá ra ngay. Link đặt mật khẩu dùng lần hai thất bại. Admin chưa bật 2FA bị yêu cầu bật |
| T04 ⚠ | Khung phân quyền: middleware theo vai trò, ApplicationPolicy và JobPolicy rỗng ban đầu, trang 403 và 404 tiếng Việt | Test ma trận: mỗi vai trò vào khu vực không thuộc mình đều nhận 403. Khách chưa đăng nhập bị chuyển về trang đăng nhập |

Cổng kiểm 0: đăng nhập được bằng ba vai trò, mỗi vai trò chỉ vào đúng khu vực của mình, CI xanh.

### 12.5. Giai đoạn 1: dữ liệu gốc và trang quản trị

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T05 | Migration và seeder cho locations (tỉnh, thành) và job_categories | Seeder chạy hai lần không sinh dòng trùng. Test số lượng và khóa duy nhất |
| T06 | Migration, model companies và company_channels. Link Shopee, TikTok, Lazada, YouTube, Facebook kiểm tra tên miền cho phép; website riêng nhận mọi URL HTTP/HTTPS hợp lệ | Test: tên miền lạ bị từ chối đối với kênh nền tảng, website riêng không cần allowlist. Chặn giao thức ngoài HTTP/HTTPS. Loại công ty chỉ nhận direct hoặc agency |
| T07 ⚠ | Bảng staff_company và company_user. Phạm vi truy vấn: nhân viên chỉ thấy công ty được phân công, tài khoản công ty chỉ thấy công ty mình | Test: nhân viên A không đọc, không sửa được công ty của nhân viên B. Đổi id trên URL vẫn nhận 403 hoặc 404 |
| T08 | Filament: thêm, sửa, tìm, lọc công ty và kênh bán hàng. Admin xác minh công ty | Test từng thao tác theo từng vai trò. Công ty chưa xác minh không đăng được tin (chặn ở T10) |
| T09 ⚠ | Filament: tạo, khóa, mở khóa tài khoản công ty và gửi email mời đặt mật khẩu | Test: không có mật khẩu nào gửi qua email. Tài khoản công ty gắn đúng công ty. Khóa xong đăng nhập không được. Email dùng giả lập khi test |
| T10 | Migration, model jobs: lương, kinh nghiệm, số lượng, trạng thái, slug, hết hạn. Ràng buộc lương tối thiểu không lớn hơn tối đa, không âm. Chỉ công ty đã xác minh mới tạo được tin | Test ràng buộc ở form và ở database. Slug trùng thì tự thêm hậu tố. Máy trạng thái chỉ cho chuyển theo đúng sơ đồ trong mục 3 |
| T11 | Filament: tạo, sửa, nhân bản, tạm dừng, đóng tin, xem trước. Nhân viên chỉ quản lý tin của công ty mình | Test phân quyền theo vai trò. Nhân bản không sao chép ngày đăng và gói đẩy tin. Rich text qua bộ lọc HTML, test chèn thẻ script bị loại |
| T12 ⚠ | Dịch vụ quét nội dung chống liên hệ ngoài: SĐT (cả viết cách chữ số, chèn dấu chấm hoặc chữ), email, chữ zalo, inbox, link lạ | Bộ test đơn vị có ít nhất 30 biến thể (ví dụ 09 0 1 2 3 4 5 6 7, không9, chữ số viết bằng chữ). Không chặn nhầm con số lương như 15.000.000. Tin vi phạm chuyển sang pending |
| T13 | Hàng chờ duyệt tin cho admin: xem lý do bị giữ, duyệt hoặc từ chối kèm lý do, thông báo cho nhân viên phụ trách | Test: chỉ admin duyệt được. Từ chối bắt buộc nhập lý do. Tin bị từ chối không hiện ra công khai |

Cổng kiểm 1: nhân viên tạo công ty, tạo tài khoản công ty, đăng tin sạch (hiện ra) và tin có SĐT (bị giữ chờ duyệt). Mọi test phân quyền xanh.

### 12.6. Giai đoạn 2: trang công khai

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T14 | Khung giao diện công khai ưu tiên điện thoại: đầu trang, chân trang, trang chủ | Kiểm tra bằng trình duyệt ở chiều rộng 360 px, không cuộn ngang. Chữ đủ lớn, nút đủ to |
| T15 | Danh sách tin: chỉ lấy tin published và chưa hết hạn, phân trang, thứ tự ổn định (thêm id vào cuối quy tắc sắp xếp) | Test: không lặp và không sót tin giữa các trang khi có tin cùng ngày đăng. Tin draft, pending, expired không xuất hiện |
| T16 | Bộ lọc: địa điểm, vị trí, mức lương, kinh nghiệm, loại công ty. Tất cả là tham số trên URL (GET) | Test từng bộ lọc và kết hợp nhiều bộ lọc. Mở lại URL đã lưu ra đúng kết quả. Bấm quay lại giữ nguyên bộ lọc và trang |
| T17 | Tìm kiếm không dấu: bật unaccent và pg_trgm, thêm chỉ mục | Test: tìm ke toan ra kế toán, tìm Nguyen ra Nguyễn. Chuỗi rỗng, ký tự đặc biệt, chuỗi rất dài không gây lỗi |
| T18 | Trang chi tiết tin: URL gồm slug và id, chuyển hướng 301 khi slug đổi, tin hết hạn vẫn mở được và hiện thông báo kèm gợi ý tin tương tự | Test 301. Tin hết hạn trả 200 kèm thông báo, tin không tồn tại trả 404. Tin draft công khai trả 404 |
| T19 | Trang công ty: giới thiệu, ngành hàng, link các kênh bán hàng và video, tin đang tuyển | Link mở tab mới với rel an toàn. Công ty chưa xác minh không có trang công khai |
| T20 | SEO: thẻ meta, Open Graph (tiêu đề, lương, địa điểm), dữ liệu JobPosting, sitemap tự cập nhật | Test sitemap chỉ có tin đang đăng. Kiểm tra dữ liệu JobPosting bằng công cụ kiểm tra của Google. Xem thử khi dán link vào Zalo |
| T21 | Chia sẻ và ghi nguồn: tham số ?nguon= được lưu lại vào phiên để gắn vào hồ sơ ở T24 | Test: nguồn chỉ nhận chuỗi ngắn, an toàn. Nguồn không làm đổi nội dung trang |

Cổng kiểm 2: mở link tin trên điện thoại thật từ Zalo, lọc, bấm quay lại, đọc chi tiết, mọi thứ đúng.

### 12.7. Giai đoạn 3: ứng tuyển và lưu file

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T22 | Migration files và applications: ULID công khai, company_id lưu kèm, cờ đồng ý, nguồn, xóa mềm. Ràng buộc duy nhất theo (tin, SĐT đã chuẩn hóa) | Test ràng buộc ở database. Xóa mềm tin hoặc công ty không làm mất hồ sơ |
| T23 ⚠ | Dịch vụ lưu file: kiểm tra loại thật bằng nội dung, giới hạn 10 MB, đổi tên thành UUID, lưu ngoài thư mục web, theo cấu trúc cv/công-ty/vị-trí/hồ-sơ, tính mã băm SHA-256 | Test: PDF đúng được nhận. File .exe đổi thành .pdf bị từ chối. File 10 MB cộng 1 byte bị từ chối. Không có đường dẫn nào truy cập file trực tiếp từ internet |
| T24 ⚠ | Form ứng tuyển: họ tên, SĐT, email (tùy chọn), CV, portfolio, ô đồng ý xử lý dữ liệu, captcha. Chuẩn hóa SĐT (0912345678, +84912345678 và 84 912 345 678 là một). Mã chống gửi lặp | Test: thiếu trường bắt buộc, SĐT sai, nộp trùng cùng tin, bấm gửi hai lần liên tiếp, không tích đồng ý. Dữ liệu đã nhập được giữ lại khi có lỗi |
| T25 | Portfolio: nhận link hoặc file dưới 10 MB (PDF, JPG, PNG) | Test: chặn javascript: và link không phải http, https. Link không bao giờ được server tự mở. Cho phép bỏ trống |
| T26 ⚠ | Giao dịch cho việc nộp hồ sơ: lưu applications và files cùng lúc, lỗi thì hoàn tác và xóa file. Lệnh dọn file tạm và file mồ côi | Test: giả lập lỗi ở bước lưu database thì không còn file thừa. Lệnh dọn không xóa file của hồ sơ hợp lệ |
| T27 ⚠ | Công việc nền: quét virus bằng ClamAV, đổi DOC/DOCX sang PDF xem trước, cập nhật trạng thái file (pending, clean, infected) | Test dùng bộ quét giả. File nhiễm bị khóa, không cho xem hay tải. Lỗi quét thì thử lại tối đa 3 lần rồi đánh dấu cần xem tay. Ứng viên không phải chờ bước này |
| T28 | Trang cảm ơn sau khi nộp. Email tóm tắt cho nhà tuyển dụng (chỉ nói có hồ sơ mới ở vị trí nào) | Test: nội dung email không chứa tên, SĐT, email của ứng viên. Email gửi bằng hàng đợi |

Cổng kiểm 3: nộp thử hồ sơ từ điện thoại với các loại file, nộp trùng, mất mạng giữa chừng. Không còn file mồ côi, không còn hồ sơ lỗi.

### 12.8. Giai đoạn 4: xem hồ sơ và phân quyền

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T29 | Migration và model access_grants, orders kèm ràng buộc ends_at lớn hơn starts_at | Test ràng buộc. Hàm kiểm tra quyền còn hiệu lực tính theo giờ hiện tại |
| T30 ⚠ | ApplicationPolicy với ba hành động: xem tóm tắt, xem chi tiết, tải file | Test ma trận đầy đủ: chưa mua, đang hiệu lực, vừa hết hạn, bị thu hồi, đúng tin, tin khác, công ty khác, tài khoản bị khóa; quyền theo tin luôn bao gồm hồ sơ nộp trước ngày mua. Dùng Carbon::setTestNow cho ngày giờ biên |
| T31 ⚠ | Danh sách hồ sơ cho tài khoản công ty: chỉ họ tên, vị trí, thời gian nộp, có tìm kiếm và lọc | Test: dữ liệu trả về không có SĐT, email, đường dẫn file, cả ở HTML lẫn JSON. Công ty chỉ thấy hồ sơ của chính mình |
| T32 ⚠ | Trang chi tiết hồ sơ khi có quyền: SĐT, email, portfolio, đổi trạng thái, ghi chú. Khi chưa có quyền hiện nút yêu cầu mở hồ sơ | Test: không quyền thì 403 và không lộ hồ sơ có tồn tại hay không. Ghi chú của công ty này không hiện ở công ty khác |
| T33 ⚠ | Đường dẫn phục vụ file: kiểm tra quyền mỗi lần, đọc dạng luồng, chế độ xem (inline) và tải (attachment), link ký tên hết hạn sau vài phút, header nosniff | Test: link hết hạn trả 403. Đổi mã trên link trả 403. File chưa quét xong hoặc nhiễm không phục vụ. Quyền hết hạn giữa chừng thì lần tải kế tiếp bị chặn |
| T34 | Xem PDF trong trang, hiển thị trạng thái "đang xử lý" khi bản xem trước chưa sẵn sàng, nút tải bản gốc | Kiểm tra trên điện thoại và máy tính. File Word hiển thị bản PDF xem trước, tải về là bản Word gốc |
| T35 ⚠ | Nhật ký truy cập audit_logs: ghi mọi lần xem chi tiết, xem file, tải file, cấp, thu hồi quyền, đăng nhập. Giới hạn tốc độ tải file, cảnh báo khi một tài khoản xem hoặc tải bất thường | Test: mỗi hành động tạo đúng một dòng nhật ký. Nhật ký không chứa SĐT hay nội dung CV. Vượt giới hạn tốc độ bị chặn và có cảnh báo cho admin |
| T36 | Giới hạn số phiên đăng nhập đồng thời của tài khoản công ty (ví dụ tối đa 2) | Test: đăng nhập phiên thứ ba thì phiên cũ nhất bị đăng xuất |

Cổng kiểm 4: dùng hai tài khoản công ty khác nhau, thử mọi cách để xem hồ sơ của nhau (đổi id, dán link file của nhau, dùng link cũ). Tất cả phải bị chặn. Đây là cổng quan trọng nhất, nên có người ngoài thử phá.

### 12.9. Giai đoạn 5: thanh toán thủ công và gói đẩy tin

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T37 | Nhà tuyển dụng bấm yêu cầu mở hồ sơ, hệ thống tạo orders trạng thái pending kèm hướng dẫn chuyển khoản | Test: một công ty không tạo trùng đơn đang chờ cho cùng phạm vi. Đơn gắn đúng công ty của tài khoản |
| T38 ⚠ | Admin xác nhận thanh toán: cập nhật đơn thành paid và tạo access_grants trong cùng một giao dịch | Test: bấm xác nhận hai lần chỉ tạo một quyền. Lỗi giữa chừng thì đơn vẫn pending. Chỉ admin xác nhận được. Ghi nhật ký người xác nhận |
| T39 ⚠ | Admin thu hồi hoặc gia hạn quyền. Hiệu lực tức thì | Test: thu hồi xong, tải file và xem chi tiết bị chặn ngay ở yêu cầu kế tiếp, không cần đăng xuất. Gia hạn nối tiếp đúng ngày |
| T40 | Migration và Filament cho plans (3 ngày, 7 ngày…) và job_boosts | Test ràng buộc ngày. Chỉ admin hoặc nhân viên phụ trách gán gói cho tin |
| T41 | Thứ tự hiển thị có gói đẩy: tin có gói đang hiệu lực lên trước (mức cao hơn trước), rồi đến ngày đăng, rồi id. Nhãn "Nổi bật" | Test: gói hết hạn thì tin về đúng thứ tự thường, không phụ thuộc lịch chạy. Đúng thứ tự khi có nhiều gói cùng lúc. Phân trang vẫn ổn định |
| T42 | Lịch chạy hằng đêm: chuyển tin hết hạn, nhắc gia hạn trước 3 ngày, đánh dấu quyền hết hạn, dọn file tạm, xóa hồ sơ quá thời gian lưu trữ | Test với Carbon::setTestNow ở ngày giờ biên. Chạy hai lần liên tiếp không gây thay đổi thừa. Lệnh xóa hồ sơ xóa cả file đi kèm |

Cổng kiểm 5: chạy trọn vòng: công ty yêu cầu, admin xác nhận, công ty xem được hồ sơ, admin thu hồi, công ty bị chặn. Mua gói đẩy, tin lên đầu, hết hạn, tin về chỗ cũ.

### 12.10. Giai đoạn 6: hoàn thiện

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T43 | Nút "Báo cáo tin" cho ứng viên và màn hình xử lý của admin | Test: giới hạn số báo cáo từ cùng một người, có captcha. Admin gỡ tin từ báo cáo được |
| T44 | Nút "Sao chép nội dung đăng Zalo" trong trang quản trị, kèm link có ?nguon= | Nội dung có vị trí, lương, địa điểm, link. Test link đúng và có tham số nguồn |
| T45 | Trang thống kê cho admin: lượt xem tin, số hồ sơ, doanh thu, nguồn ứng viên | Số liệu khớp với dữ liệu mẫu trong test. Truy vấn không chạy chậm với dữ liệu lớn (kiểm tra bằng 10.000 dòng) |
| T46 ⚠ | Admin xóa thủ công cho trường hợp pháp lý hợp lệ, xóa thông tin cá nhân và file, ghi nhật ký; không có chức năng ứng viên tự xóa | Test: sau xóa không còn thông tin cá nhân hoặc file; chỉ giữ thống kê không định danh. Nhà tuyển dụng không còn thấy hồ sơ |
| T47 | Trang tổng quan cho nhà tuyển dụng: số tin, số hồ sơ mới, quyền còn hạn bao nhiêu ngày, nút yêu cầu mở | Test hiển thị đúng với từng trạng thái quyền |
| T48 ⚠ | Rà soát cuối: kiểm tra bảo mật theo danh sách ở mục 12.12, kiểm thử trên điện thoại thật, kịch bản sao lưu và khôi phục, hướng dẫn triển khai lên máy chủ | Danh sách rà soát tick đủ. Khôi phục thử từ bản sao lưu thành công. Có tài liệu triển khai từng bước |

### 12.11. Bảng truy vết: tính năng nằm ở task nào

Dùng bảng này để tick, tránh sót tính năng.

| Tính năng | Task |
| --- | --- |
| Nhân viên thêm công ty, vị trí, đăng tin | T06, T08, T10, T11 |
| Nhân viên tạo tài khoản cho công ty | T09 |
| Công ty chỉ xem hồ sơ của mình | T07, T30, T31, T32 |
| Hồ sơ lưu theo công ty và vị trí | T22, T23, T31 |
| Chưa mua chỉ thấy tên, vị trí, thời gian | T30, T31, T32 |
| Mua quyền, admin cấp quyền | T29, T37, T38, T39 |
| Xem và tải CV trên hệ thống | T33, T34 |
| Portfolio link hoặc file dưới 10 MB | T25, T33 |
| Chặn đăng SĐT, Zalo, email | T10, T12, T13 |
| Lọc theo địa điểm, vị trí, lương, kinh nghiệm | T15, T16, T17 |
| Link riêng, quay lại giữ bộ lọc | T16, T18 |
| Gói đẩy tin 3 ngày, 7 ngày | T40, T41, T42 |
| Link kênh bán hàng của công ty | T06, T19 |
| Link đăng Zalo, ghi nguồn | T21, T44 |
| Nhật ký truy cập | T35 |
| Chống chia sẻ tài khoản | T36 |
| Bảo vệ dữ liệu cá nhân | T24, T42, T46 |

### 12.12. Danh sách rà soát cuối (T48)

Hai tài khoản công ty khác nhau không xem được dữ liệu của nhau bằng bất kỳ đường nào (giao diện, URL, link file, API).

Không có file nào truy cập được trực tiếp qua địa chỉ internet.

Không có SĐT, email, đường dẫn file trong dữ liệu trả về khi chưa có quyền.

Quyền bị thu hồi hoặc hết hạn thì có hiệu lực ngay ở yêu cầu kế tiếp.

Không có SĐT hay nội dung CV trong log và nhật ký.

Mọi trang nhập liệu có chống CSRF, chống XSS, giới hạn tốc độ.

Đã thử kịch bản nộp hồ sơ mất mạng giữa chừng và nộp trùng.

Đã thử khôi phục từ bản sao lưu.

Chạy được trên điện thoại thật, kết nối chậm.

Có điều khoản sử dụng, chính sách dữ liệu, ô đồng ý của ứng viên.
