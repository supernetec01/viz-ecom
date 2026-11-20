<?php

return [
    'app_name' => 'Viz Ecom',
    'invalid_channel_url' => 'Liên kết không hợp lệ hoặc không thuộc tên miền phù hợp với loại kênh.',
    'security' => 'Bảo mật tài khoản',
    'auth' => [
        'login' => 'Đăng nhập', 'forgot' => 'Gửi liên kết đặt lại mật khẩu', 'reset' => 'Đặt lại mật khẩu',
        'confirm' => 'Xác nhận', 'two_factor' => 'Xác thực hai bước', 'email' => 'Email', 'password' => 'Mật khẩu',
        'password_confirmation' => 'Nhập lại mật khẩu', 'code' => 'Mã từ ứng dụng Authenticator', 'recovery_code' => 'Hoặc dùng mã khôi phục',
        'remember' => 'Ghi nhớ đăng nhập', 'enable' => 'Bật xác thực hai bước', 'enable_help' => 'Admin và nhân viên cần bật xác thực hai bước để tiếp tục.',
        'scan_help' => 'Quét mã QR bằng ứng dụng Authenticator rồi nhập mã xác nhận.', 'enabled' => 'Xác thực hai bước đã được bật.',
        'recovery_help' => 'Lưu mã khôi phục ở nơi an toàn. Mỗi mã chỉ dùng một lần.', 'continue' => 'Vào khu vực làm việc',
        'reset_expires' => 'Liên kết chỉ dùng một lần và hết hạn sau 24 giờ.', 'updated' => 'Yêu cầu của bạn đã được xử lý.',
    ],
    'companies' => [
        'singular' => 'công ty', 'plural' => 'Công ty', 'name' => 'Tên công ty',
        'type' => 'Loại công ty', 'types' => ['direct' => 'Công ty chủ việc', 'agency' => 'Công ty trung gian'],
        'tax_code' => 'Mã số thuế', 'address' => 'Địa chỉ', 'introduction' => 'Giới thiệu',
        'industry' => 'Ngành hàng', 'products' => 'Sản phẩm kinh doanh', 'staff' => 'Nhân viên phụ trách',
        'verified' => 'Đã xác minh', 'verify' => 'Xác minh công ty',
    ],
    'employers' => [
        'title' => 'Tài khoản nhà tuyển dụng', 'name' => 'Người đại diện', 'status' => 'Trạng thái',
        'states' => ['active' => 'Hoạt động', 'locked' => 'Đã khóa'],
        'create' => 'Tạo tài khoản và gửi lời mời', 'resend' => 'Gửi lại lời mời', 'lock' => 'Khóa', 'unlock' => 'Mở khóa',
        'invitation_subject' => 'Lời mời tạo mật khẩu tài khoản tuyển dụng',
        'invitation_line' => 'Bạn được mời sử dụng tài khoản tuyển dụng của công ty :company.', 'set_password' => 'Đặt mật khẩu',
    ],
    'channels' => [
        'label' => 'Kênh bán hàng và video', 'type' => 'Loại kênh', 'name' => 'Tên hiển thị', 'url' => 'Liên kết',
        'types' => ['shopee' => 'Shopee', 'tiktok' => 'TikTok', 'lazada' => 'Lazada', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'website' => 'Website'],
    ],
    'login' => 'Đăng nhập',
    'logout' => 'Đăng xuất',
    'forbidden' => 'Bạn không có quyền truy cập.',
    'not_found' => 'Không tìm thấy trang.',
    'forbidden_title' => 'Truy cập bị từ chối',
    'not_found_title' => 'Không tìm thấy',
    'signed_in' => 'Bạn đã đăng nhập.',
    'account' => 'Tài khoản',
    'areas' => [
        'admin' => 'Khu vực quản trị',
        'staff' => 'Khu vực nhân viên',
        'employer' => 'Khu vực nhà tuyển dụng',
    ],
];
