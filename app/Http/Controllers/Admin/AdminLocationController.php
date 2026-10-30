<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminLocationController extends Controller
{
    /**
     * Countries.
     *
     * Every country with its number of cities and whether it is offered in forms and the location filters.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function countries(): array
    {
        return ['data' => Country::withCount('cities')->orderBy('name_en')->get()->map(fn (Country $c) => [
            'id' => $c->id, 'code' => $c->code, 'nameEn' => $c->name_en, 'nameAr' => $c->name_ar, 'isActive' => (bool) $c->is_active, 'cities' => $c->cities_count,
        ])->all()];
    }

    /**
     * Turn a country on or off.
     *
     * @return array{success: bool}
     */
    public function updateCountry(Request $request, int $country): array
    {
        $data = $request->validate(['isActive' => ['required', 'boolean']]);
        Country::findOrFail($country)->update(['is_active' => $data['isActive']]);
        $this->forget();

        return ['success' => true];
    }

    /**
     * Cities of a country.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function cities(int $country): array
    {
        return ['data' => City::where('country_id', Country::findOrFail($country)->id)->orderBy('name_en')->get()->map(fn (City $c) => [
            'id' => $c->id, 'nameEn' => $c->name_en, 'nameAr' => $c->name_ar, 'isActive' => (bool) $c->is_active,
        ])->all()];
    }

    /**
     * Add a city.
     */
    /**
     * Add a country.
     *
     * Two-letter ISO code (e.g. `SA`, `EG`) and its English and Arabic names; it is shown on the site straight away.
     */
    public function storeCountry(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', 'unique:countries,code'],
            'nameEn' => ['required', 'string', 'max:100'],
            'nameAr' => ['required', 'string', 'max:100'],
        ]);
        $country = Country::create(['code' => $data['code'], 'name_en' => $data['nameEn'], 'name_ar' => $data['nameAr'], 'is_active' => true]);
        $this->forget();

        return response()->json(['data' => [
            'id' => $country->id, 'code' => $country->code, 'nameEn' => $country->name_en, 'nameAr' => $country->name_ar, 'isActive' => true, 'cities' => 0,
        ]], 201);
    }

    public function storeCity(Request $request, int $country): JsonResponse
    {
        $data = $request->validate(['nameEn' => ['required', 'string', 'max:100'], 'nameAr' => ['required', 'string', 'max:100']]);
        $city = City::create(['country_id' => Country::findOrFail($country)->id, 'name_en' => $data['nameEn'], 'name_ar' => $data['nameAr']]);
        $this->forget();

        return response()->json(['data' => ['id' => $city->id, 'nameEn' => $city->name_en, 'nameAr' => $city->name_ar, 'isActive' => true]], 201);
    }

    /**
     * Edit a city.
     *
     * Rename it or turn it off (`isActive: false`); profiles and projects keep it.
     *
     * @return array{success: bool}
     */
    public function updateCity(Request $request, int $city): array
    {
        $data = $request->validate(['nameEn' => ['sometimes', 'string', 'max:100'], 'nameAr' => ['sometimes', 'string', 'max:100'], 'isActive' => ['sometimes', 'boolean']]);
        City::findOrFail($city)->update(collect(['nameEn' => 'name_en', 'nameAr' => 'name_ar', 'isActive' => 'is_active'])
            ->filter(fn ($column, $input) => array_key_exists($input, $data))->mapWithKeys(fn ($column, $input) => [$column => $data[$input]])->all());
        $this->forget();

        return ['success' => true];
    }

    /**
     * Drops the cached country and city lists, so edits show up right away.
     */
    private function forget(): void
    {
        Cache::forget('countries');
        foreach (['en', 'ar', 'fr'] as $locale) {
            Cache::forget("countries:{$locale}");
        }
    }
}
