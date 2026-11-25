<?php
declare(strict_types=1);

namespace Leads;

/**
 * Suggested searches: curated motivator combinations offered in the Leads page dropdown.
 * Unlike saved lists they live in code (so they improve with each release) and nobody can delete them.
 * Counts are refreshed hourly together with saved-list counts.
 */
final class Suggestions
{
    public const GROUPS = [
        'best' => 'Best first',
        'deadline' => 'Deadlines (act fast)',
        'life' => 'Inherited / estates',
        'landlord' => 'Landlords & absentee owners',
        'condition' => 'Property condition',
        'traced' => 'After skip trace',
    ];

    /** @return array<string, array{group:string, name:string, why:string, query:array}> */
    public static function all(): array
    {
        $person = ['none' => ['entity']];
        return [
            'hot_people' => ['group' => 'best', 'name' => 'Hot leads: individuals, houses (score 70+)',
                'why' => 'Every red lead owned by a person, houses only. The broadest high-quality list: start here when you need volume.',
                'query' => $person + ['min' => 70, 'ptype' => 'house']],
            'stacked3' => ['group' => 'best', 'name' => 'Stacked distress: 3+ problems',
                'why' => 'Three or more different problems on one property (tax, blight, fire, vacancy, inheritance…). Owners under several pressures at once.',
                'query' => $person + ['stack' => 3]],
            'stacked2_absentee' => ['group' => 'best', 'name' => '2+ problems, absentee owner',
                'why' => "Two or more problems and the owner doesn't live there: no emotional attachment, easier to sell.",
                'query' => ['all' => ['absentee'], 'none' => ['entity'], 'stack' => 2]],

            'sheriff' => ['group' => 'deadline', 'name' => 'Mortgage foreclosure, in redemption',
                'why' => "Sold at a sheriff's sale in the last 6 months. The owner can still sell and keep the equity before redemption ends. Small list, highest urgency: call these first.",
                'query' => ['all' => ['sheriff_sale']]],
            'tax_pending' => ['group' => 'deadline', 'name' => 'Tax foreclosure pending (Dec–Mar)',
                'why' => 'On the current Treasurer list: they lose the house on March 31 unless they pay. Empty until the 2027 list loads in early December.',
                'query' => $person + ['all' => ['tax_pending']]],
            'demolition' => ['group' => 'deadline', 'name' => 'On the demolition list',
                'why' => 'The city plans to demolish the house: the owner must act or lose the structure.',
                'query' => ['all' => ['demolition']]],

            'inherited_behind' => ['group' => 'life', 'name' => 'Inherited and behind on taxes or blight',
                'why' => "Heirs who inherited a house and aren't keeping up with it: the classic \"don't want it\" seller.",
                'query' => $person + ['all' => ['inherited'], 'any' => ['tax', 'blight']]],
            'inherited_absentee' => ['group' => 'life', 'name' => 'Inherited, heirs live elsewhere',
                'why' => 'Recently inherited and the tax bill goes to another address: the heirs are not living in it.',
                'query' => $person + ['all' => ['inherited', 'absentee']]],
            'estate_owner' => ['group' => 'life', 'name' => "Still in a deceased owner's name",
                'why' => 'Owner of record reads "Estate of", "Heirs"…: probate likely open or never done. Reach the heirs.',
                'query' => ['all' => ['estate_owner']]],

            'tired_landlords' => ['group' => 'landlord', 'name' => 'Tired landlords',
                'why' => 'Registered rentals with tax, blight, fire or compliance trouble, owned by people rather than companies.',
                'query' => $person + ['all' => ['landlord'], 'any' => ['tax', 'blight', 'fire', 'rental_noncompliant']]],
            'noncompliant_rentals' => ['group' => 'landlord', 'name' => 'Rentals without a compliance certificate',
                'why' => 'Detroit can escrow rent and fine these landlords; many would rather sell than fix.',
                'query' => $person + ['all' => ['rental_noncompliant']]],
            'out_of_state' => ['group' => 'landlord', 'name' => 'Out-of-state owners with distress',
                'why' => "Owner lives outside Michigan and the property has problems: hard to manage from afar.",
                'query' => $person + ['all' => ['out_of_state'], 'any' => ['tax', 'blight', 'fire', 'vacant', 'rental_noncompliant'], 'ptype' => 'house']],
            'long_absentee' => ['group' => 'landlord', 'name' => 'Owned 20+ years, absentee, behind',
                'why' => 'Long ownership usually means high equity; absentee plus tax or blight debt means a tired owner.',
                'query' => $person + ['all' => ['long_owned', 'absentee'], 'any' => ['tax', 'blight']]],
            'long_owner_occupied' => ['group' => 'landlord', 'name' => 'Long-time residents behind on taxes',
                'why' => 'Owned 20+ years, living there, on the tax list: often older owners with equity who may want to downsize. Handle with care.',
                'query' => ['all' => ['long_owned', 'tax'], 'none' => ['absentee', 'entity'], 'ptype' => 'house']],

            'fire' => ['group' => 'condition', 'name' => 'Fire-damaged houses',
                'why' => 'Building fire in the last 3 years: repairs are expensive and insurance may be short.',
                'query' => $person + ['all' => ['fire']]],
            'vacant_behind' => ['group' => 'condition', 'name' => 'Vacant and behind',
                'why' => 'Registered vacant with tax or blight debt: costing the owner money every month.',
                'query' => ['all' => ['vacant'], 'any' => ['tax', 'blight']]],
            'blight_absentee' => ['group' => 'condition', 'name' => 'Code violations, absentee owner',
                'why' => 'Open blight tickets on a house the owner does not live in.',
                'query' => $person + ['all' => ['blight', 'absentee'], 'ptype' => 'house']],

            'callable_hot' => ['group' => 'traced', 'name' => 'Ready to call: hot leads with a callable phone',
                'why' => 'Already skip traced, score 70+, at least one phone that is not DNC or opted out. Your call list.',
                'query' => ['min' => 70, 'traced' => 'phone']],
            'equity_absentee' => ['group' => 'traced', 'name' => 'High equity, absentee (traced)',
                'why' => 'DealMachine shows 50%+ equity and the owner lives elsewhere: room for a discount.',
                'query' => ['all' => ['high_equity', 'absentee']]],
            'free_clear' => ['group' => 'traced', 'name' => 'Free and clear, with distress (traced)',
                'why' => 'No mortgage and at least one problem: the easiest deals to structure.',
                'query' => ['all' => ['free_clear'], 'stack' => 1]],
        ];
    }

    /** Query string for the Leads page (includes s=<key> so the page can show the explanation). */
    public static function url(string $key): string
    {
        $s = self::all()[$key] ?? null;
        return $s ? http_build_query($s['query'] + ['s' => $key]) : '';
    }

    /** Recounts every suggestion over the working pool (hourly, with saved lists). */
    public static function countAll(): array
    {
        $counts = [];
        foreach (self::all() as $key => $s) {
            [$where, $params] = LeadQuery::where(LeadQuery::filters($s['query']));
            $counts[$key] = (int)Db::val("SELECT COUNT(*) FROM properties p WHERE $where", $params);
        }
        Db::setSetting('suggestion_counts', (string)json_encode(['at' => gmdate('Y-m-d H:i:s'), 'counts' => $counts]));
        return $counts;
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        return json_decode((string)Db::setting('suggestion_counts', ''), true)['counts'] ?? [];
    }
}
