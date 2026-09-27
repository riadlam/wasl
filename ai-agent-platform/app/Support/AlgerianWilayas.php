<?php

namespace App\Support;

class AlgerianWilayas
{
    /**
     * Official 58 wilayas (code, French name, Arabic name).
     *
     * @return list<array{code: string, name_fr: string, name_ar: string}>
     */
    public static function all(): array
    {
        return [
            ['code' => '01', 'name_fr' => 'Adrar', 'name_ar' => 'أدرار'],
            ['code' => '02', 'name_fr' => 'Chlef', 'name_ar' => 'الشلف'],
            ['code' => '03', 'name_fr' => 'Laghouat', 'name_ar' => 'الأغواط'],
            ['code' => '04', 'name_fr' => 'Oum El Bouaghi', 'name_ar' => 'أم البواقي'],
            ['code' => '05', 'name_fr' => 'Batna', 'name_ar' => 'باتنة'],
            ['code' => '06', 'name_fr' => 'Béjaïa', 'name_ar' => 'بجاية'],
            ['code' => '07', 'name_fr' => 'Biskra', 'name_ar' => 'بسكرة'],
            ['code' => '08', 'name_fr' => 'Béchar', 'name_ar' => 'بشار'],
            ['code' => '09', 'name_fr' => 'Blida', 'name_ar' => 'البليدة'],
            ['code' => '10', 'name_fr' => 'Bouira', 'name_ar' => 'البويرة'],
            ['code' => '11', 'name_fr' => 'Tamanrasset', 'name_ar' => 'تمنراست'],
            ['code' => '12', 'name_fr' => 'Tébessa', 'name_ar' => 'تبسة'],
            ['code' => '13', 'name_fr' => 'Tlemcen', 'name_ar' => 'تلمسان'],
            ['code' => '14', 'name_fr' => 'Tiaret', 'name_ar' => 'تيارت'],
            ['code' => '15', 'name_fr' => 'Tizi Ouzou', 'name_ar' => 'تيزي وزو'],
            ['code' => '16', 'name_fr' => 'Alger', 'name_ar' => 'الجزائر'],
            ['code' => '17', 'name_fr' => 'Djelfa', 'name_ar' => 'الجلفة'],
            ['code' => '18', 'name_fr' => 'Jijel', 'name_ar' => 'جيجل'],
            ['code' => '19', 'name_fr' => 'Sétif', 'name_ar' => 'سطيف'],
            ['code' => '20', 'name_fr' => 'Saïda', 'name_ar' => 'سعيدة'],
            ['code' => '21', 'name_fr' => 'Skikda', 'name_ar' => 'سكيكدة'],
            ['code' => '22', 'name_fr' => 'Sidi Bel Abbès', 'name_ar' => 'سيدي بلعباس'],
            ['code' => '23', 'name_fr' => 'Annaba', 'name_ar' => 'عنابة'],
            ['code' => '24', 'name_fr' => 'Guelma', 'name_ar' => 'قالمة'],
            ['code' => '25', 'name_fr' => 'Constantine', 'name_ar' => 'قسنطينة'],
            ['code' => '26', 'name_fr' => 'Médéa', 'name_ar' => 'المدية'],
            ['code' => '27', 'name_fr' => 'Mostaganem', 'name_ar' => 'مستغانم'],
            ['code' => '28', 'name_fr' => 'M\'Sila', 'name_ar' => 'المسيلة'],
            ['code' => '29', 'name_fr' => 'Mascara', 'name_ar' => 'معسكر'],
            ['code' => '30', 'name_fr' => 'Ouargla', 'name_ar' => 'ورقلة'],
            ['code' => '31', 'name_fr' => 'Oran', 'name_ar' => 'وهران'],
            ['code' => '32', 'name_fr' => 'El Bayadh', 'name_ar' => 'البيض'],
            ['code' => '33', 'name_fr' => 'Illizi', 'name_ar' => 'إليزي'],
            ['code' => '34', 'name_fr' => 'Bordj Bou Arréridj', 'name_ar' => 'برج بوعريريج'],
            ['code' => '35', 'name_fr' => 'Boumerdès', 'name_ar' => 'بومرداس'],
            ['code' => '36', 'name_fr' => 'El Tarf', 'name_ar' => 'الطارف'],
            ['code' => '37', 'name_fr' => 'Tindouf', 'name_ar' => 'تندوف'],
            ['code' => '38', 'name_fr' => 'Tissemsilt', 'name_ar' => 'تيسمسيلت'],
            ['code' => '39', 'name_fr' => 'El Oued', 'name_ar' => 'الوادي'],
            ['code' => '40', 'name_fr' => 'Khenchela', 'name_ar' => 'خنشلة'],
            ['code' => '41', 'name_fr' => 'Souk Ahras', 'name_ar' => 'سوق أهراس'],
            ['code' => '42', 'name_fr' => 'Tipaza', 'name_ar' => 'تيبازة'],
            ['code' => '43', 'name_fr' => 'Mila', 'name_ar' => 'ميلة'],
            ['code' => '44', 'name_fr' => 'Aïn Defla', 'name_ar' => 'عين الدفلى'],
            ['code' => '45', 'name_fr' => 'Naâma', 'name_ar' => 'النعامة'],
            ['code' => '46', 'name_fr' => 'Aïn Témouchent', 'name_ar' => 'عين تموشنت'],
            ['code' => '47', 'name_fr' => 'Ghardaïa', 'name_ar' => 'غرداية'],
            ['code' => '48', 'name_fr' => 'Relizane', 'name_ar' => 'غليزان'],
            ['code' => '49', 'name_fr' => 'Timimoun', 'name_ar' => 'تيميمون'],
            ['code' => '50', 'name_fr' => 'Bordj Badji Mokhtar', 'name_ar' => 'برج باجي مختار'],
            ['code' => '51', 'name_fr' => 'Ouled Djellal', 'name_ar' => 'أولاد جلال'],
            ['code' => '52', 'name_fr' => 'Béni Abbès', 'name_ar' => 'بني عباس'],
            ['code' => '53', 'name_fr' => 'In Salah', 'name_ar' => 'عين صالح'],
            ['code' => '54', 'name_fr' => 'In Guezzam', 'name_ar' => 'عين قزام'],
            ['code' => '55', 'name_fr' => 'Touggourt', 'name_ar' => 'تقرت'],
            ['code' => '56', 'name_fr' => 'Djanet', 'name_ar' => 'جانت'],
            ['code' => '57', 'name_fr' => 'El M\'Ghair', 'name_ar' => 'المغير'],
            ['code' => '58', 'name_fr' => 'El Meniaa', 'name_ar' => 'المنيعة'],
        ];
    }
}
