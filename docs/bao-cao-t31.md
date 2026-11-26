# Báo cáo T31 — Danh sách hồ sơ nhà tuyển dụng

## Đã làm

Filament /app/ho-so cho employer active, chỉ họ tên tóm tắt/vị trí/thời gian Việt Nam. Tìm tên/vị trí, lọc tin và khoảng ngày nộp, phân trang10/25/50, thứ tự mặc định submitted_at/id ổn định. Chưa có link chi tiết, mua quyền, xem/tải file hoặc thao tác sửa/xóa. Admin/staff không vào resource employer này.

Mọi query bắt đầu từ ApplicationPolicy, chọn allowlist5 trường (id/job_id phục vụ nội bộ), không hydrate contacts/file/portfolio/notes. SQL scope theo các công ty đang liên kết, không theo company_id gửi từ client. Livewire JSON cũng chỉ dữ liệu tóm tắt, kể cả tài khoản đã mua quyền.

Theo lựa chọn “chỉ che các chuỗi giống SĐT/email/link”: thêm function PostgreSQL immutable ở migration mới000018, không thay dữ liệu gốc. Che từng chuỗi, giữ phần tên còn lại; SELECT/search dùng cùng kết quả trước PHP/hydration. NFKC, ký tự ẩn phổ biến, số có dấu ngăn/country code, email chuẩn và at/dot, URL có/không scheme, dãy chữ số tiếng Việt có ca thử. Không thêm thư viện/extension. Nhãn [ẩn liên hệ] ở file ngôn ngữ.

## Tiêu chí đạt kèm test

`EmployerApplicationsTest`:403 staff/admin, query từ chối actor stale/locked, yêu cầu login. HTML và Livewire snapshot/effects JSON không contact/file/portfolio/notes/foreign rows, SQL projection đúng cột cho cả paid/unpaid. Che liên hệ trong tên và JSON, không dò được chuỗi gốc qua search; bản gốc DB giữ nguyên. Unicode/dấu câu/escape HTML. Search OR không vượt scope, job options không lộ công ty khác, date UTC/Vietnam biên00h và until exclusive; input sai/quá dài/ngày đảo bị chặn. Soft deletes theo T30; revoke membership/role khi component đang mở; phân trang không trùng và không action detail/file.

Nhóm focused trước bộ tổng:34 test,185 assertions; bổ sung assertion JSON che tên ở lượt cuối. Composer test/lint/CI commit cuối ghi trong PR. Test route T27 cập nhật vì /app/ho-so là index hợp lệ ở T31, không coi nó là route tải file. Không chạy lại UI không liên quan.

Browser local: employer không mua chỉ thấy2 hồ sơ công ty mình, không có hồ sơ công ty khác. Tên chứa SĐT/email/link hiển thị từng phần [ẩn liên hệ]; DOM/snapshot không có các sentinel nhạy cảm. Search Nguyễn còn1 dòng; lọc ngày hiện tại còn đúng1 dòng và nhãn bộ lọc hiện rõ; không console error. Tài khoản, công ty, job, hồ sơ và metadata thử đã dọn, không tạo file ứng viên thật hoặc gửi email bên ngoài.

## Chưa đạt / giới hạn

T32/T33 mới chi tiết/file, T35 audit, T37 yêu cầu mua. Không nhận dữ liệu thật trước đủ các lớp đó. PDF production giữ tắt theo T23. Bộ che tên là heuristic: có thể che nhầm chuỗi số/domain trong tên, không chứng minh chặn mọi cách viết mã hóa hoặc liên hệ bằng ngôn ngữ tự nhiên. Không thay kiểm tra field projection/phân quyền. Chưa benchmark dữ liệu lớn hoặc kiểm tra môi trường production.

## Giả định

PostgreSQL18 UTF-8, role migration có quyền tạo function; deploy migration trước mã truy vấn T31. NFKC có thể chuẩn hóa cách biểu diễn Unicode nhưng không sửa tên gốc trong DB. Tài khoản có thể liên kết nhiều công ty, chỉ lấy trong các liên kết hiện tại. Không có API JSON riêng mới; kiểm tra JSON là phản hồi Livewire thực dùng bởi UI.

## Đề xuất

T32 dùng projection chi tiết riêng sau Gate, không mở rộng summary thành select*. Bổ sung regression khi phát hiện mẫu né bộ che tên; T48 đo query/search trên dữ liệu thật, kiểm tra collation Unicode và quyền tạo function trên máy chủ. Dừng sau T31 để người dùng xem lại.
