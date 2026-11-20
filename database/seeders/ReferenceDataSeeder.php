<?php

namespace Database\Seeders;

use App\Models\JobCategory;
use App\Models\Location;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // Nguồn mã tỉnh: https://baochinhphu.vn/bang-danh-muc-va-ma-so-cua-34-tinh-thanh-moi-3321-don-vi-hanh-chinh-cap-xa-moi-102250704153652947.htm
        $provinces = [
            '01' => 'Hà Nội', '04' => 'Cao Bằng', '08' => 'Tuyên Quang',
            '11' => 'Điện Biên', '12' => 'Lai Châu', '14' => 'Sơn La',
            '15' => 'Lào Cai', '19' => 'Thái Nguyên', '20' => 'Lạng Sơn',
            '22' => 'Quảng Ninh', '24' => 'Bắc Ninh', '25' => 'Phú Thọ',
            '31' => 'Hải Phòng', '33' => 'Hưng Yên', '37' => 'Ninh Bình',
            '38' => 'Thanh Hóa', '40' => 'Nghệ An', '42' => 'Hà Tĩnh',
            '44' => 'Quảng Trị', '46' => 'Huế', '48' => 'Đà Nẵng',
            '51' => 'Quảng Ngãi', '52' => 'Gia Lai', '56' => 'Khánh Hòa',
            '66' => 'Đắk Lắk', '68' => 'Lâm Đồng', '75' => 'Đồng Nai',
            '79' => 'Hồ Chí Minh', '80' => 'Tây Ninh', '82' => 'Đồng Tháp',
            '86' => 'Vĩnh Long', '91' => 'An Giang', '92' => 'Cần Thơ', '96' => 'Cà Mau',
        ];
        foreach ($provinces as $code => $name) {
            Location::updateOrCreate(['code' => str_pad((string) $code, 2, '0', STR_PAD_LEFT)], ['name' => $name]);
        }

        foreach ([
            'kinh-doanh' => 'Kinh doanh', 'kho-van' => 'Kho vận', 'marketing' => 'Marketing',
            'livestream' => 'Livestream', 'cham-soc-khach-hang' => 'Chăm sóc khách hàng',
            'ke-toan' => 'Kế toán', 'nhan-su' => 'Nhân sự', 'cong-nghe-thong-tin' => 'Công nghệ thông tin',
        ] as $slug => $name) {
            JobCategory::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
