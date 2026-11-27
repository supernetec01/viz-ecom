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

locations: danh mục tỉnh/thành dùng cho lựa chọn và lọc tin. Địa chỉ làm việc theo yêu cầu bổ sung ngày 21/11/2026 dùng mô hình tỉnh/thành → xã/phường/đặc khu → số nhà, đường hoặc thôn/tổ dân phố; không yêu cầu quận/huyện.

job_categories: nhóm vị trí (kinh doanh, kho vận, marketing, livestream, CSKH…).

jobs: id, company_id, category_id, location_id (tỉnh/thành), ward_name (xã/phường/đặc khu, tối đa 150 ký tự), work_address (số nhà, đường/thôn/tổ dân phố, có thể thêm tòa nhà/tầng, tối đa 500 ký tự), tiêu đề, slug, mô tả, yêu cầu, quyền lợi, lương tối thiểu, lương tối đa, cờ "thỏa thuận", yêu cầu kinh nghiệm (số năm), số lượng cần tuyển, hình thức (toàn thời gian, bán thời gian), trạng thái (draft, pending, published, paused, expired, closed), published_at, expires_at, created_by, thời điểm xóa mềm.

Địa chỉ làm việc lưu theo từng tin, không tự sao chép từ trụ sở công ty. Bắt buộc đủ ba phần khi tạo/sửa bản nháp và gửi bản nháp đăng tin. Xã/phường/đặc khu hiện nhập văn bản theo tên mới; chưa xác minh tên bằng danh mục mã xã, chưa có bản đồ/geocoding. Đổi tỉnh trên form xóa hai ô địa chỉ còn lại để nhập lại. Bản ghi cũ giữ null cả hai trường, xem trước báo thiếu, không tự suy ra địa chỉ; vẫn cho phép tạm dừng/đóng/hết hạn tin cũ. Bản nháp cũ cần bổ sung trước khi lưu/gửi/nhân bản. Địa chỉ không nhận HTML và được quét cùng nội dung tin tại T12. T18 phải dùng địa chỉ đầy đủ trên trang chi tiết cho ứng viên; không chỉ hiển thị tỉnh.

plans: id, tên gói (ví dụ 3 ngày, 7 ngày), số ngày, mức ưu tiên, giá.

job_boosts: id, job_id, plan_id, starts_at, ends_at, priority, order_id.

applications: id (ULID công khai), job_id, company_id (lưu thêm để lọc nhanh), họ tên, SĐT (đã chuẩn hóa), email (tùy chọn), cv_file_id, portfolio_url, portfolio_file_id, đã đồng ý xử lý dữ liệu (thời điểm), nguồn (?nguon=), submitted_at, trạng thái (new, viewed, interview, hired, rejected), ghi chú nội bộ, thời điểm xóa mềm.

files: id, đường dẫn lưu, tên gốc, MIME đã kiểm tra, dung lượng, mã băm SHA-256, scan_status (pending, clean, infected, error), preview_status (pending, ready, failed), đường dẫn PDF xem trước, số lượt xử lý/quét/preview, mã lỗi, cờ xem tay, phiên xử lý và hash/thời điểm đã quét.

Ánh xạ schema T22: applications dùng id ULID, full_name, phone_normalized (dạng 0 + 9 chữ số), consent_given/consented_at, source, submitted_at và internal_notes. Unique theo job_id/phone_normalized khi deleted_at null. FK ghép job_id/company_id chống ghi nhầm công ty; xóa mềm tin/công ty không cascade hồ sơ. Files chỉ là metadata ở T22; MIME thật, UUID và lưu ngoài web root do T23 kiểm tra/thực hiện, không có luồng upload/download trong T22.

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

### 3.3. Máy trạng thái tin tuyển dụng (đã duyệt)

| Trạng thái hiện tại | Được chuyển sang |
| --- | --- |
| draft | pending, published (chỉ qua bước quét nội dung T12) |
| pending | draft, published |
| published | paused, closed, expired |
| paused | published (chưa hết hạn), closed, expired, draft |
| expired | draft |
| closed | Không chuyển tiếp |

Tin mới luôn là draft. Chỉ service quét nội dung T12 mới được đưa draft trực tiếp sang published khi sạch; tin cần duyệt chuyển pending. T11 không cung cấp nút đăng hoặc duyệt tin. Khi đăng, ghi published_at và yêu cầu expires_at còn hiệu lực, sau published_at. Khi quay lại draft để sửa/đăng lại, xóa ngày đăng và ngày hết hạn cũ. Chỉ sửa nội dung trong draft; tin đã closed có thể nhân bản thành tin mới, không mở lại tin cũ. Các chuyển trạng thái khác đều bị từ chối.

Quyết định paused ngày 21/11/2026:

- published → paused; paused không cho sửa nội dung hoặc thời gian. Muốn sửa phải về draft và đi lại luồng quét/duyệt.
- Tiếp tục paused → published giữ published_at và expires_at. Nếu đã quá hạn, không tiếp tục; chuyển expired. Không dừng đồng hồ, không bù ngày cho hạn tin hoặc job_boosts.
- T15/T16: không hiển thị paused trong danh sách công khai. T18: link chi tiết cũ báo "đang tạm dừng nhận hồ sơ", khóa form, không trả 404. T24: server từ chối ứng tuyển khi paused.
- T30–T34: giữ hồ sơ cũ; tài khoản có quyền còn hiệu lực vẫn được xem. T40/T41: tiếp tục không thay đổi thời hạn hoặc thứ tự gói đẩy tin.
- T42: lịch đêm chuyển cả published và paused quá hạn sang expired. T20: không đưa paused vào sitemap.
- Test tại T11: mọi cặp chuyển trạng thái, chặn sửa paused, tiếp tục trước/quá hạn và giữ thời gian. Test form ứng tuyển bị khóa và thứ tự gói đẩy tin thực hiện khi có các chức năng tương ứng, không tạo trước ở T11.

Laravel database queue dùng bảng queue_jobs, tách khỏi bảng jobs nghiệp vụ; migration mới đổi tên và giữ dữ liệu hàng đợi cũ.

### 3.4. Trang chi tiết công khai và nhận hồ sơ — quyết định T18 ngày 21/11/2026

| Trạng thái | HTTP và nội dung trang chi tiết |
| --- | --- |
| published còn hạn, đã tới ngày đăng | 200, đầy đủ nội dung và địa chỉ làm việc; đủ điều kiện nhận hồ sơ |
| paused | 200, đầy đủ nội dung kèm thông báo tạm dừng, không nhận hồ sơ, gợi ý tin tương tự |
| expired hoặc published/paused đã tới hạn theo đồng hồ | 200, đầy đủ nội dung kèm thông báo hết hạn, không nhận hồ sơ, gợi ý tin tương tự |
| closed | 200, chỉ tiêu đề, tên công ty (link từ T19), địa điểm, thông báo “Tin đã đóng, không nhận hồ sơ” và gợi ý tối thiểu; không mô tả/yêu cầu/quyền lợi/lương |
| draft, pending, không tồn tại, xóa mềm, công ty không còn xác minh hoặc chưa tới ngày đăng | 404, không lộ nội dung hoặc redirect tới slug thật |
| bị gỡ vì vi phạm (cờ riêng T43) | 404, không lộ nội dung; chưa tạo cờ trong T18 |

Mọi trang không còn nhận hồ sơ có noindex, không JobPosting và không vào sitemap. T20 dùng scope công khai cho sitemap và hàm nhận hồ sơ cho JobPosting. Chi tiết dùng Cache-Control no-store để không giữ nội dung cũ sau đổi trạng thái. Slug cũ chỉ 301 sau kiểm tra công khai; slug vẫn giữ nguyên khi sửa tiêu đề theo T10, 301 áp dụng khi slug lưu thật sự đổi.

`Job::canReceiveApplications()` là hàm chung duy nhất: published, đã tới ngày đăng, chưa tới thời điểm hết hạn và chưa xóa mềm. T18 dùng hàm cho khu vực ứng tuyển/thông báo khóa; unit test mọi trạng thái và ngày biên. T18 không tạo POST/form/lưu hồ sơ/file hoặc route tạm. T24 đọc lại tin qua scope công khai, gọi đúng hàm và test POST trực tiếp bị từ chối cho paused/expired/closed. T19 mới tạo trang công ty và gắn link từ view con tên công ty ở mọi trạng thái được hiển thị. Các điểm tích hợp ở `docs/CODEMAP.md`.

## 4. Luồng xử lý

### 4.1. Nhân viên tạo công ty và đăng tin

Nhân viên đăng nhập. Server kiểm tra vai trò và bắt buộc 2FA cho admin, nhân viên.

Nhân viên tạo công ty. Hệ thống ghi vào companies, company_channels và gán công ty cho nhân viên đó trong staff_company.

Nhân viên tạo vị trí, điền theo form có sẵn: địa điểm, lương, kinh nghiệm, mô tả. Tin ở trạng thái draft.

Khi gửi đăng, hệ thống kiểm tra quyền và quét nội dung mới nhất trong một giao dịch có khóa bản ghi. Tin sạch chuyển draft → published nếu ngày hết hạn hợp lệ. Tin có dấu hiệu liên hệ ngoài chuyển draft → pending để duyệt tay; chưa có ngày đăng (mục 3.3).

T12 quét title, description, requirements và benefits; bổ sung ward_name và work_address khi tách địa chỉ làm việc ngày 21/11/2026. Không quét giới thiệu hoặc kênh công ty. SĐT/email/Zalo/inbox tạo lý do theo trường và loại vi phạm; không ghi đoạn nội dung hoặc số/email khớp vào log. Lý do link ngoài lưu thêm URL gây giữ để người duyệt T13 thấy ngay; metadata này không nằm trong JSON mặc định của model.

Quyết định link T12 ngày 21/11/2026: chỉ link có hostname trùng tên miền chuẩn trong APP_URL (sau chuẩn hóa IDNA/hoa thường) hoặc đường dẫn nội bộ được qua. Mọi link ngoài trong nội dung tin đều cần duyệt: có/không HTTP(S), www, link rút gọn, zalo.me/wa.me/t.me/m.me, tên miền hoa thường hoặc Unicode giống Latin. Kiểm tra href thật của thẻ a thay vì chỉ chữ hiển thị. Ảnh nhúng ngoài bị bộ lọc HTML loại; bộ quét cũng nhận diện src ngoài khi được gọi với HTML chưa lọc. Không tự thêm allowlist theo kênh công ty hoặc tên miền con; không mở URL từ server.

T12 cung cấp service SubmitJob, kiểm tra policy submit và chỉ nhận tin draft của admin/nhân viên được phân công. Hook lưu model tự quét trước chuyển từ draft sang pending/published, để lời gọi publish trực tiếp cũng không bỏ qua bước giữ tin. Về draft hoặc sửa nội dung thì xóa kết quả cũ và quét lại khi gửi đăng. Tin pending chỉ được quyết định duyệt/từ chối qua luồng T13; không gửi đăng lặp để vượt duyệt. Nhân viên vẫn có thể rút tin pending của công ty mình về draft theo quyền redraft đã có; đây không phải quyết định từ chối của admin.

T13: nút Gửi đăng trên trang xem trước và menu thao tác tin nối với SubmitJob. Hàng chờ `/app/duyet-tin` chỉ dành cho admin đang hoạt động; chỉ hiển thị pending, có lý do quét theo trường và URL ngoài dạng văn bản đã thoát ký tự. Duyệt yêu cầu hạn tin còn hiệu lực và dữ liệu hợp lệ (gồm công ty đã xác minh, địa chỉ đầy đủ), chuyển pending → published. Từ chối bắt buộc lý do văn bản không rỗng, tối đa 2.000 ký tự, chuyển pending → draft và xóa ngày đăng/hết hạn theo máy trạng thái. Quyết định, lý do, người duyệt và thời điểm UTC được lưu trong job_reviews; không nhận người duyệt từ trình duyệt. Mỗi yêu cầu khóa và đọc lại tin, kiểm tra lại quyền admin; không xử lý lặp hoặc dùng dữ liệu trạng thái cũ.

Quyết định T13 và thông báo database được lưu trong cùng giao dịch; lỗi ghi thông báo thì hoàn tác cả quyết định và trạng thái. Thông báo trong chuông Filament gửi cho tất cả nhân viên role staff còn hoạt động, đang được phân công công ty tại lúc quyết định. Không gửi email/SMS ở T13, không cần SMTP hoặc queue worker. Thông báo chỉ có nội dung chung và link; không chép nội dung tin, URL ngoài hoặc lý do từ chối. Mở link kiểm tra lại quyền công ty. Nhân viên xem kết quả duyệt gần nhất/lý do trên trang xem trước thuộc quyền của mình; lịch sử giữ qua các lần sửa và gửi lại. Không đưa quan hệ latestReview vào JSON mặc định của Job.

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

Quyết định T26: cleanup mặc định dry-run, --force rõ ràng mới xóa; file ít nhất 24 giờ theo mtime, kiểm tra lại trước xóa, không tham chiếu files.path/preview_path và không active lease. Giữ file hồ sơ xóa mềm và metadata không gắn hồ sơ; chỉ liệt kê metadata đó trong dry-run. Root canonical riêng, không symlink/.. hoặc thư mục tạm hệ thống. Dừng toàn kế hoạch khi orphan >20% hoặc files rỗng nhưng đĩa >10 file; DB lỗi không được coi là orphan. Log số lượng/lý do, không tên gốc. Production force và lịch tự chạy mặc định tắt, chỉ bật sau review dry-run thật. Chi tiết ở CODEMAP/báo cáo T26; không phải chức năng xóa PII sau 6 tháng.

Quyết định ngày 25/11/2026: người dùng yêu cầu tiếp tục hai task T24–T25 sau khi được thông báo T23 còn thiếu mẫu. Cho phép triển khai nối tiếp trên nhánh/PR riêng từ T23 draft; không coi T23 đã hoàn tất, không merge T23 hoặc bật PDF production. Quyết định này thay điều kiện phải chờ T23 đủ mẫu mới viết T24; các tiêu chí tương thích và chốt production của T23 vẫn giữ nguyên.

### 5.1. Quy định file

| Loại | Định dạng cho phép | Dung lượng tối đa |
| --- | --- | --- |
| CV | PDF và DOCX; DOC cũ/DOCM chưa nhận | 10 MB |
| Portfolio dạng file | PDF, JPG, PNG | 10 MB |
| Portfolio dạng link | URL bắt đầu bằng http hoặc https | Không áp dụng |

Không nhận file nén, file chạy được, hoặc file có macro.

Quyết định bổ sung T23: DOC cũ bị từ chối, hướng dẫn lưu lại PDF hoặc DOCX; không thêm công cụ đọc DOC. DOCX kiểm tra package, chặn VBA/macro, OLE/ActiveX, quan hệ ngoài, path nguy hiểm và zip bomb. Người dùng đã duyệt qpdf JSON/object stream và runner cô lập, không thêm Composer package. Đã có kiểm tra sâu PDF, từ chối mã hóa (cả mật khẩu rỗng) và JS/JavaScript/OpenAction/AA/Launch/GoToR/GoToE/SubmitForm/ImportData/XFA/RichMedia/3D/EmbeddedFile cùng marker liên quan, giữ nguyên gốc. Fail closed với lỗi/thiếu công cụ/timeout/RAM/output/object/depth limit. Hướng dẫn/test/số đo ở docs/pdf-inspector-t48.md. PDF production vẫn tắt, T23 draft chờ mẫu điện thoại và Canva không đính kèm. Quyết định tiếp tục T24–T27 theo yêu cầu sau đó không thay các chốt production này.

### 5.2. Xử lý khi upload

Kiểm tra loại thật của file bằng nội dung, không chỉ dựa vào đuôi file hay loại do trình duyệt khai báo.

Đổi tên file thành chuỗi ngẫu nhiên (UUID). Tên gốc chỉ lưu trong database để hiển thị.

Lưu ngoài thư mục web, không bao giờ có đường dẫn trực tiếp từ internet.

Cấu trúc thư mục: cv/{công-ty}/{vị-trí}/{mã-hồ-sơ}/, thuận tiện quản lý và xóa theo công ty hoặc vị trí.

Giới hạn dung lượng được kiểm tra ở cả Nginx, PHP và Laravel để người dùng nhận thông báo rõ thay vì lỗi trắng trang.

Quét virus trước khi cho phép xem. File chưa quét xong hiển thị trạng thái "đang xử lý" cho nhà tuyển dụng.

Chỉ DOCX: tạo PDF bằng LibreOffice cô lập không mạng, giữ bản gốc; DOC cũ chưa nhận. Dùng InitialPage=0 để không sinh OpenAction, rồi qua đúng qpdf T23 và ClamAV; không sửa PDF upload hoặc nới denylist. Không có chuẩn hóa hậu kỳ vì cấu hình xuất đã cho kết quả tương đương.

Quyết định T27: tối đa ba lượt thực chạy, chờ 1 phút rồi 5 phút khi lỗi tạm; lỗi chắc chắn không retry. Hết lỗi scan: error + xem tay, không xem/tải. Chỉ preview lỗi: clean/failed, vẫn đủ trạng thái tải gốc; thông báo “Không có bản xem trước, tải về để xem”. Virus ở gốc hoặc preview: infected, không xem/tải và không retry. Admin chỉ thấy mã hồ sơ ULID, mã file, trạng thái, lượt, mã lỗi và thời gian; không định danh/nội dung. Retry kiểm tra hash và chỉ chạy bước lỗi, ghi actor/time/file qua hook T35. FileReadiness dùng chung cho T30/T33; route nội dung chờ T33, T27 không tạo trước.

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

Chống tấn công thường gặp: CSRF (Laravel có sẵn), SQL injection (dùng truy vấn tham số hóa của Laravel, không nối chuỗi), XSS (thoát ký tự khi hiển thị; rich text dùng Symfony HtmlSanitizer đã có trong Filament, được người dùng duyệt thay HTMLPurifier ngày 21/11/2026), clickjacking (X-Frame-Options), và tiêu đề Content-Security-Policy. Nội dung tin chỉ nhận định dạng văn bản cơ bản và link HTTP/HTTPS; loại script, thuộc tính sự kiện, style và nội dung nhúng. Giới hạn mỗi trường rich text 50.000 ký tự, không tải file qua trình soạn thảo T11.

IDOR: mã hồ sơ công khai dùng ULID hoặc UUID thay vì số tăng dần. Quan trọng hơn, mỗi truy cập đều qua policy, nên đổi mã trên link cũng không xem được hồ sơ công ty khác.

Ngoại lệ CSP Filament được duyệt ngày 21/11/2026: chỉ `/app` và `/app/*` (trừ trang đăng nhập `/app/login`) có `unsafe-eval` trong script-src. Script quản trị dùng nonce ngẫu nhiên theo từng yêu cầu từ `Vite::useCspNonce()` của Laravel, không dùng `unsafe-inline`. Trang công khai và đăng nhập không có `unsafe-eval` hoặc `unsafe-inline` cho script. Không thêm gói Composer.

Mọi đường dẫn phục vụ CV/portfolio ở T33 trở đi phải gắn `csp.private-file`: CSP riêng `default-src 'none'; script-src 'none'; …; sandbox`, ưu tiên cao hơn ngoại lệ `/app`, không cho eval hoặc inline. Tại T11 chỉ xây middleware/profile và test header, chưa xây luồng phục vụ file của task sau. Khi có file thật phải kiểm tra tương thích sandbox và quyền/audit ở mỗi lượt truy cập.

Lý do ngoại lệ: runtime CSP-safe của Livewire hiện chưa chạy được các biểu thức Filament sinh ra. Khi Filament hỗ trợ và đã kiểm tra thực tế bằng runtime CSP-safe thì bỏ ngoại lệ. Mỗi lần thay đổi CSP phải sửa test: nonce `/app` đổi theo yêu cầu và khớp script; `/app` có eval nhưng không inline; công khai/đăng nhập không eval; file riêng tư không eval và có sandbox. Xem README để biết chi tiết vận hành.

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

Triển khai T14/T15 ngày 21/11/2026: trang chủ `/` dùng Blade layout công khai, header/footer và CSS build tại chỗ, không cần JavaScript hoặc font ngoài. `/viec-lam` chỉ lấy jobs chưa xóa mềm, status=published, published_at <= hiện tại và expires_at > hiện tại; công ty vẫn phải tồn tại, chưa xóa mềm và đủ xác minh. Sắp xếp published_at DESC, id DESC, 12 tin/trang qua tham số page; không dùng trạng thái hoặc company_id do người xem truyền để mở rộng dữ liệu. Thẻ hiển thị tên tin/công ty/loại công ty, nhóm vị trí, lương VND, hình thức, kinh nghiệm và địa chỉ đầy đủ nếu đã có. Tin cũ thiếu địa chỉ chỉ hiện tỉnh kèm thông báo thiếu, không tự suy ra trụ sở. Chỉ chọn dữ liệu công khai và eager load quan hệ cần thiết; không lấy mô tả liên hệ, metadata quét hoặc lịch sử duyệt nội bộ cho danh sách. Chưa có bộ lọc, tìm kiếm, chi tiết hoặc nút ứng tuyển ở T15; bổ sung theo T16–T18 và các task hồ sơ.

T16 dùng URL GET `/viec-lam?dia_diem=1&vi_tri=1&luong_tu=10000000&kinh_nghiem=1&loai_cong_ty=direct&page=2` (ID ví dụ, dùng danh mục của database đang chạy). Các bộ lọc kết hợp AND; phân trang giữ tham số hợp lệ và form không mang page khi đổi bộ lọc. Mở URL đã lưu hoặc bấm quay lại phục hồi lựa chọn và trang. Lương là salary_min đã công bố đạt ngưỡng 5/10/15/20/30 triệu, loại thỏa thuận khi lọc lương; kinh nghiệm là yêu cầu tối đa 0/1/2/3/5/10 năm. Giá trị đã biết nhưng sai trả HTTP 422 tiếng Việt, không redirect lặp. Không lưu bộ lọc riêng trong JavaScript/session.

Thẻ tin: vị trí, công ty, lương, địa điểm, nhãn "Nổi bật" cho tin đang đẩy.

T17 tìm kiếm bằng q trong tiêu đề tin hoặc tên công ty, không dấu/hoa thường, Unicode NFKC và khoảng trắng chuẩn hóa. Tìm chuỗi con cả cụm từ, không fuzzy/ranking; giữ ngày đăng DESC, ID DESC. q tối đa 200 ký tự, rỗng bỏ điều kiện, sai kiểu/quá dài/NUL trả 422. Tham số SQL được bind và ký tự LIKE được escape. Migration mới bật unaccent/pg_trgm, cột search_text do trigger duy trì/backfill và GIN trigram index trên jobs/companies; không đổi dữ liệu gốc, không quét hồ sơ hoặc nội dung nội bộ, không trả search_text qua JSON. Từ khóa kết hợp bộ lọc T16 trên cùng URL và giữ qua phân trang/Back.

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
| T10 | Migration, model jobs: lương, kinh nghiệm, số lượng, trạng thái (có paused), slug, hết hạn. Ràng buộc lương tối thiểu không lớn hơn tối đa, không âm. Chỉ công ty đã xác minh mới tạo được tin | Test ràng buộc ở form và ở database. Slug trùng thì tự thêm hậu tố. Máy trạng thái chỉ cho chuyển theo đúng sơ đồ trong mục 3.3; bổ sung paused bằng migration mới tại T11 |
| T11 | Filament: tạo, sửa bản nháp, nhân bản, tạm dừng/tiếp tục, đóng tin, xem trước. Nhân viên chỉ quản lý tin của công ty mình | Test phân quyền theo vai trò. Nhân bản không sao chép ngày đăng, hạn tin và gói đẩy tin. Rich text qua Symfony HtmlSanitizer, test script/XSS bị loại. Paused không sửa, tiếp tục giữ ngày cũ, quá hạn chuyển expired. Các test công khai/ứng tuyển/gói thực hiện ở task tương ứng theo mục 3.3 |
| T12 ⚠ | Dịch vụ quét nội dung chống liên hệ ngoài: SĐT (cả viết cách chữ số, chèn dấu chấm hoặc chữ), email, chữ zalo, inbox. Mọi link ngoài APP_URL trong nội dung tin cần duyệt; không có allowlist theo kênh công ty | Ít nhất 30 biến thể liên hệ. Không giữ nhầm lương 15.000.000 hoặc địa chỉ 12.5 km. Tin vi phạm sang pending và lưu lý do, URL ngoài cụ thể. Test riêng từng dạng link http/https, bare domain/www, link rút gọn/nhắn tin, href ẩn, hoa thường/Unicode và trộn nội bộ/ngoài; không quét thông tin/kênh công ty |
| T13 | Hàng chờ duyệt tin cho admin: xem lý do bị giữ, duyệt hoặc từ chối kèm lý do, thông báo cho nhân viên phụ trách | Test: chỉ admin duyệt được. Từ chối bắt buộc nhập lý do. Tin bị từ chối không hiện ra công khai |

Cổng kiểm 1: nhân viên tạo công ty, tạo tài khoản công ty, đăng tin sạch (hiện ra) và tin có SĐT (bị giữ chờ duyệt). Mọi test phân quyền xanh.

### 12.6. Giai đoạn 2: trang công khai

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T14 | Khung giao diện công khai ưu tiên điện thoại: đầu trang, chân trang, trang chủ | Kiểm tra bằng trình duyệt ở chiều rộng 360 px, không cuộn ngang. Chữ đủ lớn, nút đủ to |
| T15 | Danh sách tin: chỉ lấy tin published và chưa hết hạn, phân trang, thứ tự ổn định (thêm id vào cuối quy tắc sắp xếp) | Test: không lặp và không sót tin giữa các trang khi có tin cùng ngày đăng. Tin draft, pending, expired không xuất hiện |
| T16 | Bộ lọc: địa điểm, vị trí, mức lương, kinh nghiệm, loại công ty. Tất cả là tham số trên URL (GET) | Test từng bộ lọc và kết hợp nhiều bộ lọc. Mở lại URL đã lưu ra đúng kết quả. Bấm quay lại giữ nguyên bộ lọc và trang |
| T17 | Tìm kiếm không dấu: bật unaccent và pg_trgm, thêm chỉ mục | Test: tìm ke toan ra kế toán, tìm Nguyen ra Nguyễn. Chuỗi rỗng, ký tự đặc biệt, chuỗi rất dài không gây lỗi |
| T18 | Chi tiết slug-id, 301 khi slug đổi; hiển thị theo mục 3.4, closed chỉ tối thiểu; hàm chung canReceiveApplications và CODEMAP. Không POST/form/route công ty tạm | Mỗi trạng thái một test HTTP/nội dung; 301; closed không có mô tả/yêu cầu/quyền lợi/lương trong HTML; noindex khi không nhận; unit test hàm chung đủ 6 trạng thái và ngày hết hạn biên |
| T19 | Trang công ty: giới thiệu, ngành hàng, kênh bán hàng/video, tin đang tuyển; gắn link tên công ty trong view con T18 cho published/paused/expired/closed | Link kênh mở tab mới với rel an toàn. Công ty chưa xác minh không có trang công khai. T18 chỉ có tên dạng chữ, không route công ty tạm |
| T20 | SEO: thẻ meta, Open Graph (tiêu đề, lương, địa điểm), dữ liệu JobPosting, sitemap tự cập nhật | Test sitemap chỉ có tin đang đăng. Kiểm tra dữ liệu JobPosting bằng công cụ kiểm tra của Google. Xem thử khi dán link vào Zalo |
| T21 | Chia sẻ và ghi nguồn: tham số ?nguon= được lưu lại vào phiên để gắn vào hồ sơ ở T24 | Test: nguồn chỉ nhận chuỗi ngắn, an toàn. Nguồn không làm đổi nội dung trang |

Cổng kiểm 2: mở link tin trên điện thoại thật từ Zalo, lọc, bấm quay lại, đọc chi tiết, mọi thứ đúng.

### 12.7. Giai đoạn 3: ứng tuyển và lưu file

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T22 | Migration files và applications: ULID công khai, company_id lưu kèm, cờ đồng ý, nguồn, xóa mềm. Ràng buộc duy nhất theo (tin, SĐT đã chuẩn hóa) | Test ràng buộc ở database. Xóa mềm tin hoặc công ty không làm mất hồ sơ |
| T23 ⚠ | Dịch vụ lưu file: kiểm tra loại thật bằng nội dung, giới hạn 10 MB, đổi tên thành UUID, lưu ngoài thư mục web, theo cấu trúc cv/công-ty/vị-trí/hồ-sơ, tính mã băm SHA-256 | Test: PDF đúng được nhận. File .exe đổi thành .pdf bị từ chối. File 10 MB cộng 1 byte bị từ chối. Không có đường dẫn nào truy cập file trực tiếp từ internet |
| T24 ⚠ | Form ứng tuyển: họ tên, SĐT, email (tùy chọn), CV, portfolio, ô đồng ý xử lý dữ liệu, captcha. Chuẩn hóa SĐT (0912345678, +84912345678 và 84 912 345 678 là một). Mã chống gửi lặp. Trước lưu gọi đúng Job::canReceiveApplications T18 trên bản ghi đọc lại qua scope công khai | Test: thiếu trường bắt buộc, SĐT sai, nộp trùng cùng tin, bấm gửi hai lần liên tiếp, không tích đồng ý. POST trực tiếp vào paused/expired/closed bị từ chối ở server, mỗi trạng thái một test. Dữ liệu đã nhập được giữ lại khi có lỗi |
| T25 | Portfolio: nhận link hoặc file dưới 10 MB (PDF, JPG, PNG) | Test: chặn javascript: và link không phải http, https. Link không bao giờ được server tự mở. Cho phép bỏ trống |
| T26 ⚠ | Giao dịch cho việc nộp hồ sơ: lưu applications và files cùng lúc, lỗi thì hoàn tác và xóa file. Lệnh dọn file tạm và file mồ côi | Test: giả lập lỗi ở bước lưu database thì không còn file thừa. Lệnh dọn không xóa file của hồ sơ hợp lệ |
| T27 ⚠ | Worker ClamAV, chỉ chuyển DOCX bằng LibreOffice cô lập; scan pending/clean/infected/error, preview pending/ready/failed; admin metadata/retry | Ba lượt thực chạy, backoff 60/300s; infected không retry; error chặn readiness; chỉ lỗi preview vẫn tải gốc clean. Khóa/idempotent, qpdf T23 + AV trước ready, test 403, không route nội dung. Chi tiết ở 5.2/báo cáo T27 |
| T28 | Trang cảm ơn sau khi nộp. Email tóm tắt cho nhà tuyển dụng (chỉ nói có hồ sơ mới ở vị trí nào) | Test: nội dung email không chứa tên, SĐT, email của ứng viên. Email gửi bằng hàng đợi |

Quyết định T28 ngày26/11: mọi employer active thuộc công ty, đọc lại vai trò/trạng thái/liên kết khi worker chạy; không staff/admin. Email chỉ tên công ty/tiêu đề vị trí/số mới/link /app, chưa link hồ sơ. Gộp một lô mỗi tin/người nhận mỗi giờ, counter/watermark DB; lỗi email không chặn nộp. Retry trước SMTP tối đa3 lượt; đã bắt đầu SMTP mà kết quả chưa rõ thì không tự phát lại cùng lô, log mã lỗi/đối soát vận hành. Chưa có exactly-once SMTP; uncertain có thể mất thông báo, lô sau tính phần mới hơn watermark đã thử. Không gửi mail thật khi test local; MAIL_MAILER=log cần cấu hình lại khi triển khai.

Cổng kiểm 3: nộp thử hồ sơ từ điện thoại với các loại file, nộp trùng, mất mạng giữa chừng. Không còn file mồ côi, không còn hồ sơ lỗi.

### 12.8. Giai đoạn 4: xem hồ sơ và phân quyền

Quyết định T30–T31 đã duyệt: application/company xóa mềm chặn mọi vai trò; job xóa mềm vẫn giữ khả năng đọc hồ sơ theo quyền; thu hồi xác minh công ty chưa chặn hồ sơ cũ. Admin/staff phụ trách active không cần mua; employer details/file phải grant đúng tin/company còn hiệu lực, summary không cần mua. T30 chỉ policy/backend, route test không vào production; T31 chỉ Filament danh sách employer (họ tên/vị trí/thời gian), tìm tên/vị trí, lọc tin/khoảng ngày nộp; chưa link chi tiết T32 hoặc nút mua T37.

Quyết định bổ sung T31: full_name tự nhập có thể chứa liên hệ; chỉ che từng chuỗi giống SĐT/email/link, không ẩn toàn bộ tên theo allowlist ký tự. Bản gốc giữ nguyên, SELECT và tìm kiếm summary đều dùng kết quả đã che trước đưa vào PHP/HTML/JSON. Không chọn contact/file/portfolio/notes trong query danh sách kể cả đã mua. Bộ che theo mẫu cần regression khi có cách viết né mới, không hứa nhận diện mọi mã hóa.

Quyết định T29 ngày26/11: order_id bắt buộc, một đơn tối đa một quyền; FK kép đơn/công ty và tin/công ty. Đơn phải paid và loại access_grant, kiểm tra trong transaction có khóa dòng. Tặng/dùng thử vẫn dùng đơn0đ phương thức gift/free_trial, reason bắt buộc và admin duyệt. Đơn paid không đổi trạng thái khi còn grant chưa revoked, kể cả grant đã hết hạn. T29 chỉ schema/model/guard dữ liệu và hiệu lực, không giao diện hoặc luồng thanh toán/cấp quyền T37–T39. Scope quyền chỉ theo tin; active và starts_at<=now<ends_at, đọc lại DB. Binding grant giữ nguyên sau tạo để không đổi phạm vi của đơn đã cấp.

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T29 | Migration và model access_grants, orders kèm ràng buộc ends_at lớn hơn starts_at | Test ràng buộc. Hàm kiểm tra quyền còn hiệu lực tính theo giờ hiện tại |
| T30 ⚠ | ApplicationPolicy với ba hành động: xem tóm tắt, xem chi tiết, tải file | Test ma trận đầy đủ: chưa mua, đang hiệu lực, vừa hết hạn, bị thu hồi, đúng tin, tin khác, công ty khác, tài khoản bị khóa; quyền theo tin luôn bao gồm hồ sơ nộp trước ngày mua. Dùng Carbon::setTestNow cho ngày giờ biên |
| T31 ⚠ | Danh sách hồ sơ cho tài khoản công ty: chỉ họ tên, vị trí, thời gian nộp, có tìm kiếm và lọc | Test: dữ liệu trả về không có SĐT, email, đường dẫn file, cả ở HTML lẫn JSON. Công ty chỉ thấy hồ sơ của chính mình |
| T32 ⚠ | Trang chi tiết có quyền, trạng thái thủ công5 giá trị, ghi chú2000 ký tự dùng chung công ty, editor/vai trò/thời điểm. Khóa dòng + edit_version chống ghi đè; kiểm tra lại ApplicationPolicy khi lưu. Audit tối thiểu qua RecordAudit, bắt buộc ghi thành công trước trả chi tiết. Chưa mở khóa chỉ nhãn, nút yêu cầu/đơn sangT37 | Test403 thống nhất unpaid/foreign/missing/softdelete, expiry lúc save, conflict409, enum/length422, escape HTML, đúng một audit/action không nội dung, audit lỗi fail closed, no-store/noindex. ResourceT31 chỉ index; chi tiết Blade riêng |
| T33 ⚠ | Đường dẫn phục vụ file: kiểm tra quyền mỗi lần, đọc dạng luồng, chế độ xem (inline) và tải (attachment), link ký tên hết hạn sau vài phút, header nosniff | Test: link hết hạn trả 403. Đổi mã trên link trả 403. File chưa quét xong hoặc nhiễm không phục vụ. Quyền hết hạn giữa chừng thì lần tải kế tiếp bị chặn |
| T34 | Xem PDF trong trang, hiển thị trạng thái "đang xử lý" khi bản xem trước chưa sẵn sàng, nút tải bản gốc | Kiểm tra trên điện thoại và máy tính. File Word hiển thị bản PDF xem trước, tải về là bản Word gốc |
| T35 ⚠ | Nhật ký truy cập audit_logs: ghi mọi lần xem chi tiết, xem file, tải file, cấp, thu hồi quyền, đăng nhập. Giới hạn tốc độ tải file, cảnh báo khi một tài khoản xem hoặc tải bất thường | Test: mỗi hành động tạo đúng một dòng nhật ký. Nhật ký không chứa SĐT hay nội dung CV. Vượt giới hạn tốc độ bị chặn và có cảnh báo cho admin |
| T36 | Giới hạn số phiên đăng nhập đồng thời của tài khoản công ty (ví dụ tối đa 2) | Test: đăng nhập phiên thứ ba thì phiên cũ nhất bị đăng xuất |

Quyết định T36–T37 ngày 27/11/2026: duyệt cả ba điểm trong `docs/de-xuat-t36-t37.md`. T36 tối đa hai phiên mỗi employer, thu hồi phiên đăng nhập lâu nhất, admin/staff không giới hạn; thu hồi chặn ở request kế tiếp, kể cả Livewire/file. Cookie ghi nhớ cũ bị vô hiệu khi vượt giới hạn; phiên employer trước triển khai phải đăng nhập lại. T37 yêu cầu cấu hình giá/ngân hàng, thiếu thì chặn; phạm vi tin published/paused/expired/closed thuộc công ty và chưa xóa mềm; dùng lại pending, không tạo khi grant còn hiệu lực. Không xác nhận thanh toán/cấp quyền trước T38.

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

T37 triển khai theo quyết định đã duyệt ngày27/11: tin published/paused/expired/closed chưa xóa, employer active thuộc công ty; có effective grant không tạo thêm. Pending cùng company/job dùng lại cho mọi tài khoản của công ty; khóa và unique DB, không tin dữ liệu POST về công ty/số tiền/trạng thái. Giá VND nguyên dương và ngân hàng đủ qua config bắt buộc; không mặc định demo. Đơn giữ snapshot giá/hướng dẫn/reference khi tạo. Thiếu cấu hình trả503 và không tạo; đơn legacy pending chưa có phạm vi cần admin đối chiếu, không tự suy ra. Chỉ pending, chưa paid/cấp quyền/email/thanh toán tự động. T38 mới chọn thời hạn. Điểm tích hợp: docs/CODEMAP.md; báo cáo docs/bao-cao-t37.md.

### 12.10. Giai đoạn 6: hoàn thiện

| Mã | Việc cần làm | Tiêu chí hoàn thành và test bắt buộc |
| --- | --- | --- |
| T43 | Nút "Báo cáo tin" cho ứng viên và màn hình xử lý của admin. Tin gỡ vi phạm dùng cờ riêng, thêm vào scope công khai chung ở CODEMAP; không dùng closed thay thế | Test: giới hạn số báo cáo từ cùng một người, có captcha. Admin gỡ tin được; chi tiết tin gỡ trả 404 không lộ nội dung, không ở danh sách/gợi ý/sitemap |
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
