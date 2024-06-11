<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataExport extends Model
{
    use HasFactory;

    static public function check($u)
    {
        $company = Company::find($u->company_id);
        if ($company == null) {
            throw new \Exception('Company not found');
        }
        $cats = [
            'Family' => 'Family 1',
            'Family-2' => 'Family 2',
            'Friend' => 'Friends 1',
            'Friend-2' => 'Friends 2',
            'Workmates' => 'Workmates',
            'OBs_and_OGs' => 'OBs & OGs',
            'Others' => 'Others',
        ];
        foreach ($cats as $key => $cat) {
            $catExists = DataExport::where('category_id', $key)->where('company_id', $u->company_id)->first();
            if ($catExists == null) {
                $de = new DataExport();
                $de->company_id = $u->company_id;
                $de->treasurer_id = $u->id;
                $de->created_by_id = $u->id;
                $de->category_id = $key;
                $de->parameter_1 = $cat;
                $de->save();
            }
        }
    }
}
