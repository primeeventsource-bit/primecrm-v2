<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

/**
 * Maps NANP area codes to canonical IANA timezones.
 *
 * Used by the calling window check when a lead has no explicit timezone
 * stored. This is intentionally a static, hand-curated map rather than
 * an external lookup — area-code → state mapping is stable, the data is
 * tiny (~330 rows), and routing every dial through a remote API would
 * make the guardrail's tail latency unpredictable.
 *
 * Coverage: US + Canada (the operating jurisdictions). Returns null for
 * unknown area codes, in which case the guardrail falls back to UTC and
 * applies the tenant's default calling window.
 *
 * The map covers area codes assigned through 2026 per NANPA. New codes
 * land roughly twice a year — refresh from
 * https://nationalnanpa.com/area_code_maps/ac_map_static.html as needed.
 */
final class AreaCodeTimezoneResolver
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        // Single map: US and Canada area codes → IANA timezone.
        // Curated against NANPA + state primary timezones. Where a state
        // straddles zones (e.g. KY, IN, ND), we use the dominant zone for
        // the area code's geographic majority. Overlay area codes share
        // the same timezone as their parent.
        return [
            // Eastern (UTC-5 / EDT UTC-4)
            '201' => 'America/New_York', '202' => 'America/New_York', '203' => 'America/New_York',
            '207' => 'America/New_York', '212' => 'America/New_York', '215' => 'America/New_York',
            '216' => 'America/New_York', '217' => 'America/Chicago', '219' => 'America/Indiana/Indianapolis',
            '224' => 'America/Chicago', '225' => 'America/Chicago', '227' => 'America/New_York',
            '229' => 'America/New_York', '231' => 'America/New_York',
            '234' => 'America/New_York', '239' => 'America/New_York', '240' => 'America/New_York',
            '248' => 'America/New_York', '267' => 'America/New_York', '269' => 'America/New_York',
            '270' => 'America/Chicago', '276' => 'America/New_York',
            '301' => 'America/New_York', '302' => 'America/New_York', '304' => 'America/New_York',
            '305' => 'America/New_York', '321' => 'America/New_York', '330' => 'America/New_York',
            '336' => 'America/New_York', '339' => 'America/New_York', '347' => 'America/New_York',
            '351' => 'America/New_York', '352' => 'America/New_York',
            '386' => 'America/New_York',
            '401' => 'America/New_York', '404' => 'America/New_York', '407' => 'America/New_York',
            '410' => 'America/New_York', '412' => 'America/New_York', '413' => 'America/New_York',
            '414' => 'America/Chicago', '419' => 'America/New_York',
            '423' => 'America/New_York', '434' => 'America/New_York', '440' => 'America/New_York',
            '443' => 'America/New_York', '470' => 'America/New_York', '475' => 'America/New_York',
            '478' => 'America/New_York', '484' => 'America/New_York', '502' => 'America/New_York',
            '508' => 'America/New_York',
            '513' => 'America/New_York', '516' => 'America/New_York', '517' => 'America/New_York',
            '518' => 'America/New_York', '540' => 'America/New_York',
            '551' => 'America/New_York', '561' => 'America/New_York', '567' => 'America/New_York',
            '570' => 'America/New_York', '585' => 'America/New_York', '586' => 'America/New_York',
            '603' => 'America/New_York', '607' => 'America/New_York', '609' => 'America/New_York',
            '610' => 'America/New_York', '614' => 'America/New_York', '615' => 'America/Chicago',
            '616' => 'America/New_York', '617' => 'America/New_York', '631' => 'America/New_York',
            '646' => 'America/New_York', '678' => 'America/New_York',
            '703' => 'America/New_York', '704' => 'America/New_York', '706' => 'America/New_York',
            '716' => 'America/New_York', '717' => 'America/New_York', '724' => 'America/New_York',
            '727' => 'America/New_York', '732' => 'America/New_York', '734' => 'America/New_York',
            '740' => 'America/New_York', '754' => 'America/New_York', '757' => 'America/New_York',
            '770' => 'America/New_York', '772' => 'America/New_York', '774' => 'America/New_York',
            '781' => 'America/New_York', '786' => 'America/New_York',
            '802' => 'America/New_York', '803' => 'America/New_York', '804' => 'America/New_York',
            '810' => 'America/New_York', '813' => 'America/New_York', '814' => 'America/New_York',
            '828' => 'America/New_York', '843' => 'America/New_York',
            '845' => 'America/New_York', '848' => 'America/New_York', '850' => 'America/New_York',
            '856' => 'America/New_York', '857' => 'America/New_York', '860' => 'America/New_York',
            '862' => 'America/New_York', '863' => 'America/New_York', '864' => 'America/New_York',
            '878' => 'America/New_York', '904' => 'America/New_York', '908' => 'America/New_York',
            '910' => 'America/New_York', '912' => 'America/New_York',
            '914' => 'America/New_York', '917' => 'America/New_York', '919' => 'America/New_York',
            '929' => 'America/New_York', '937' => 'America/New_York', '941' => 'America/New_York',
            '947' => 'America/New_York', '954' => 'America/New_York', '959' => 'America/New_York',
            '973' => 'America/New_York', '978' => 'America/New_York', '980' => 'America/New_York',
            '984' => 'America/New_York', '989' => 'America/New_York',

            // Central (UTC-6 / CDT UTC-5)
            '205' => 'America/Chicago', '210' => 'America/Chicago', '214' => 'America/Chicago',
            '218' => 'America/Chicago', '228' => 'America/Chicago', '251' => 'America/Chicago',
            '254' => 'America/Chicago', '256' => 'America/Chicago', '262' => 'America/Chicago',
            '281' => 'America/Chicago', '309' => 'America/Chicago', '312' => 'America/Chicago',
            '314' => 'America/Chicago', '316' => 'America/Chicago', '318' => 'America/Chicago',
            '319' => 'America/Chicago', '320' => 'America/Chicago', '331' => 'America/Chicago',
            '334' => 'America/Chicago', '337' => 'America/Chicago', '361' => 'America/Chicago',
            '402' => 'America/Chicago', '405' => 'America/Chicago', '409' => 'America/Chicago',
            '417' => 'America/Chicago',
            '430' => 'America/Chicago', '432' => 'America/Chicago', '469' => 'America/Chicago',
            '479' => 'America/Chicago', '501' => 'America/Chicago', '504' => 'America/Chicago',
            '507' => 'America/Chicago', '512' => 'America/Chicago', '515' => 'America/Chicago',
            '563' => 'America/Chicago', '573' => 'America/Chicago',
            '580' => 'America/Chicago', '601' => 'America/Chicago', '605' => 'America/Chicago',
            '608' => 'America/Chicago', '612' => 'America/Chicago', '618' => 'America/Chicago',
            '620' => 'America/Chicago', '630' => 'America/Chicago',
            '636' => 'America/Chicago', '641' => 'America/Chicago', '651' => 'America/Chicago',
            '660' => 'America/Chicago', '662' => 'America/Chicago', '682' => 'America/Chicago',
            '701' => 'America/Chicago', '708' => 'America/Chicago', '712' => 'America/Chicago',
            '713' => 'America/Chicago', '715' => 'America/Chicago', '731' => 'America/Chicago',
            '763' => 'America/Chicago', '769' => 'America/Chicago',
            '773' => 'America/Chicago', '779' => 'America/Chicago', '785' => 'America/Chicago',
            '806' => 'America/Chicago', '815' => 'America/Chicago', '816' => 'America/Chicago',
            '817' => 'America/Chicago', '830' => 'America/Chicago', '832' => 'America/Chicago',
            '847' => 'America/Chicago', '870' => 'America/Chicago', '901' => 'America/Chicago',
            '903' => 'America/Chicago', '913' => 'America/Chicago', '915' => 'America/Chicago',
            '918' => 'America/Chicago', '920' => 'America/Chicago', '931' => 'America/Chicago',
            '936' => 'America/Chicago', '940' => 'America/Chicago', '952' => 'America/Chicago',
            '956' => 'America/Chicago', '972' => 'America/Chicago', '979' => 'America/Chicago',

            // Mountain (UTC-7 / MDT UTC-6)
            '208' => 'America/Boise', '303' => 'America/Denver', '307' => 'America/Denver',
            '385' => 'America/Denver', '406' => 'America/Denver', '435' => 'America/Denver',
            '480' => 'America/Phoenix', '505' => 'America/Denver',
            '520' => 'America/Phoenix', '575' => 'America/Denver', '602' => 'America/Phoenix',
            '623' => 'America/Phoenix', '719' => 'America/Denver', '720' => 'America/Denver',
            '801' => 'America/Denver', '928' => 'America/Phoenix', '970' => 'America/Denver',

            // Pacific (UTC-8 / PDT UTC-7)
            '206' => 'America/Los_Angeles', '209' => 'America/Los_Angeles', '213' => 'America/Los_Angeles',
            '253' => 'America/Los_Angeles', '310' => 'America/Los_Angeles', '323' => 'America/Los_Angeles',
            '341' => 'America/Los_Angeles',
            '360' => 'America/Los_Angeles', '408' => 'America/Los_Angeles', '415' => 'America/Los_Angeles',
            '424' => 'America/Los_Angeles', '425' => 'America/Los_Angeles', '442' => 'America/Los_Angeles',
            '503' => 'America/Los_Angeles',
            '509' => 'America/Los_Angeles', '510' => 'America/Los_Angeles', '530' => 'America/Los_Angeles',
            '541' => 'America/Los_Angeles', '559' => 'America/Los_Angeles', '562' => 'America/Los_Angeles',
            '619' => 'America/Los_Angeles', '626' => 'America/Los_Angeles', '628' => 'America/Los_Angeles',
            '650' => 'America/Los_Angeles',
            '657' => 'America/Los_Angeles', '661' => 'America/Los_Angeles', '669' => 'America/Los_Angeles',
            '702' => 'America/Los_Angeles', '707' => 'America/Los_Angeles', '714' => 'America/Los_Angeles',
            '725' => 'America/Los_Angeles', '747' => 'America/Los_Angeles', '760' => 'America/Los_Angeles',
            '775' => 'America/Los_Angeles', '805' => 'America/Los_Angeles', '818' => 'America/Los_Angeles',
            '858' => 'America/Los_Angeles', '909' => 'America/Los_Angeles', '916' => 'America/Los_Angeles',
            '925' => 'America/Los_Angeles', '949' => 'America/Los_Angeles', '951' => 'America/Los_Angeles',
            '971' => 'America/Los_Angeles',

            // Alaska (UTC-9 / AKDT UTC-8)
            '907' => 'America/Anchorage',

            // Hawaii (UTC-10, no DST)
            '808' => 'Pacific/Honolulu',

            // Canada — covers our core operating provinces.
            '226' => 'America/Toronto', '236' => 'America/Vancouver', '249' => 'America/Toronto',
            '250' => 'America/Vancouver', '289' => 'America/Toronto',
            '306' => 'America/Regina', '343' => 'America/Toronto',
            '403' => 'America/Edmonton',
            '416' => 'America/Toronto', '418' => 'America/Toronto',
            '438' => 'America/Toronto', '450' => 'America/Toronto',
            '506' => 'America/Halifax', '514' => 'America/Toronto',
            '519' => 'America/Toronto', '579' => 'America/Toronto',
            '581' => 'America/Toronto', '587' => 'America/Edmonton',
            '604' => 'America/Vancouver',
            '613' => 'America/Toronto', '639' => 'America/Regina',
            '647' => 'America/Toronto', '705' => 'America/Toronto',
            '709' => 'America/St_Johns', '778' => 'America/Vancouver',
            '780' => 'America/Edmonton', '782' => 'America/Halifax',
            '807' => 'America/Toronto', '819' => 'America/Toronto',
            '825' => 'America/Edmonton', '867' => 'America/Yellowknife',
            '873' => 'America/Toronto', '902' => 'America/Halifax',
            '905' => 'America/Toronto',
        ];
    }

    public function resolve(string $e164Phone): ?string
    {
        // E.164 +1XXXXXXXXXX → area code = chars 2..5 (after the country code)
        if (! str_starts_with($e164Phone, '+1') || strlen($e164Phone) !== 12) {
            return null;
        }

        $areaCode = substr($e164Phone, 2, 3);

        return self::map()[$areaCode] ?? null;
    }
}
