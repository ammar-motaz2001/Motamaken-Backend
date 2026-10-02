<?php

namespace App\Http\Controllers;

use App\Http\Resources\CityResource;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class LocationController extends Controller
{
    /**
     * List countries.
     *
     * For the "Country" dropdown of the search bar, sorted by name in the current language.
     *
     * @unauthenticated
     */
    public function countries(): AnonymousResourceCollection
    {
        $column = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        return CountryResource::collection(Country::orderBy($column)->get());
    }

    /**
     * List cities of a country.
     *
     * For the "City" dropdown, which depends on the chosen country.
     *
     * @unauthenticated
     */
    public function cities(Country $country): AnonymousResourceCollection
    {
        $column = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        return CityResource::collection($country->cities()->orderBy($column)->get());
    }
}
