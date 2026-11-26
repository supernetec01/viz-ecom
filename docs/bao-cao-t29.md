# Báo cáo T29

## Đã làm

Migration000017 và model Order/AccessGrant; FK kép order/company, job/company, unique order_id, trạng thái hợp lệ và ends_at>starts_at. VND số nguyên không âm. Đơn0đ bắt buộc gift/free_trial và reason, paid có xác nhận admin/thời điểm. Không sửa migration cũ, không seed đơn/quyền vào demo.

OrderGrantIntegrity là guard dữ liệu được model save gọi trong transaction, khóa dòng order rồi grant; kiểm tra đơn paid đúng loại/công ty, admin active khi xác nhận hoặc cấp mới. Đơn có quyền chưa revoked không đổi trạng thái/type/company, dù đã hết hạn. Refresh dữ liệu hiện tại trước áp dirty fields, chống stale model ghi lùi trạng thái. Binding grant không đổi sau tạo. Không thêm thư viện, HTTP, UI hoặc quy trình giao dịch T37–T39.

AccessGrant::isEffective/scopeEffective đọc DB hiện tại, active và starts_at<=now<ends_at, order paid/đúng loại/công ty. UTC microsecond, không cache trên object cũ. Không xét ngày nộp, không loại hồ sơ trước mua. Không thay ApplicationPolicy T30.

## Tiêu chí đạt kèm test

`AccessGrantDataTest`: các ca từ chối viết trước; thiếu đơn, chưa paid/sai loại/khác công ty,0đ thiếu reason/phương thức, thiếu xác nhận hoặc người xác nhận không phải admin, người cấp không phải admin, ngày bằng/ngược/thiếu. Đơn paid/gift/trial hợp lệ; unique một quyền/đơn; DB chặn FK kép, dates và zero reason. Kiểm tra trước/đúng starts_at, sát/trúng ends_at với microsecond và revoke trên object đã load. Chặn đổi đơn khi quyền còn active dù hết hạn; stale order không ghi lùi; không đổi binding. Helper fail closed khi SQL bảo trì làm đơn không paid.

Test khóa cạnh tranh dùng hai kết nối PostgreSQL thật, lock_timeout100ms: kết nối thứ hai không đổi order được trong transaction tạo grant (55P03). Test rollback migration cũ đã gỡ các phụ thuộc mới đúng thứ tự, không sửa nội dung migration cũ. Kết quả composer test/lint và CI commit cuối ghi ở PR.

## Chưa đạt / phần task sau

Chưa có phân quyền truy cập hồ sơ T30, route/UI danh sách/chi tiết/file, audit T35, giao diện đơn và xác nhận/cấp quyền T37–T39. Không thao tác thanh toán thật hay cấp quyền demo. Không cần test lại UI không đổi. PDF production vẫn tắt theo T23.

## Giả định

Một currency VND theo hệ thống hiện tại. Binding grant là lịch sử cố định, không tái gán sang đơn/tin/công ty/người cấp khác. Guard paid/type/admin/đổi trạng thái nằm ở service/model save, không phải FK; SQL/QueryBuilder bảo trì trực tiếp có thể vượt guard và không được dùng làm đường ghi ứng dụng. DB vẫn chặn unique/FK/CHECK; helper luôn đọc lại điều kiện paid. Model guard chưa thay authorization actor của các task UI sau.

## Đề xuất

T30 dùng đúng helper hiệu lực và policy đọc lại theo từng yêu cầu. T37–T39 dùng model/service có khóa thay raw updates; bổ sung authorization/audit vào workflow lúc có UI. Chờ xem lại T28/T29, không làm T30 trước.
