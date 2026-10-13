<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Green Impact Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1a1a1a; font-size: 13px; line-height: 1.5; }
        .header { background: #1a6b3c; color: #fff; padding: 30px 40px; }
        .header h1 { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .header p { font-size: 13px; opacity: 0.9; }
        .content { padding: 30px 40px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #1a6b3c; }
        .stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .tier-badge { display: inline-block; background: #1a6b3c; color: #fff; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-top: 8px; }
        .section-title { font-size: 16px; font-weight: 600; color: #1a6b3c; margin: 24px 0 12px; border-bottom: 2px solid #e8f5e9; padding-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #f5f5f5; text-align: left; padding: 8px 10px; font-weight: 600; border-bottom: 2px solid #ddd; }
        td { padding: 7px 10px; border-bottom: 1px solid #eee; }
        tr:nth-child(even) td { background: #fafafa; }
        .footer { margin-top: 30px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 11px; color: #888; text-align: center; }
        .positive { color: #1a6b3c; font-weight: 600; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Your Green Impact</h1>
        <p>{{ $user->first_name }} {{ $user->last_name }} &middot; Generated {{ $generatedAt }}</p>
    </div>

    <div class="content">
        <table style="border: none; margin-bottom: 10px;">
            <tr>
                <td style="border: none; padding: 10px; width: 33%; text-align: center; border: 1px solid #e5e5e5; border-radius: 8px;">
                    <div class="stat-value">{{ number_format($totalRides) }}</div>
                    <div class="stat-label">Rides</div>
                </td>
                <td style="border: none; padding: 10px; width: 33%; text-align: center; border: 1px solid #e5e5e5; border-radius: 8px;">
                    <div class="stat-value">{{ number_format($totalCo2, 1) }} kg</div>
                    <div class="stat-label">CO&#8322; Saved</div>
                </td>
                <td style="border: none; padding: 10px; width: 33%; text-align: center; border: 1px solid #e5e5e5; border-radius: 8px;">
                    <div class="stat-value">{{ number_format($totalElectricKm, 0) }}</div>
                    <div class="stat-label">Electric km</div>
                </td>
            </tr>
        </table>

        <p style="text-align: center; margin-top: 10px;">
            <span class="tier-badge">{{ $tierName }}</span>
            &nbsp;&nbsp;
            {{ number_format($profile->total_ranking_points) }} points
        </p>

        @if($recentScores->isNotEmpty())
        <h3 class="section-title">Recent Trip Impact</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Distance</th>
                    <th>CO&#8322; Saved</th>
                    <th>Points</th>
                    <th>Multiplier</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentScores as $score)
                <tr>
                    <td>{{ $score->created_at->format('M d, Y') }}</td>
                    <td>{{ number_format($score->distance_km, 1) }} km</td>
                    <td class="positive">{{ number_format($score->co2_saved * 1000, 0) }} g</td>
                    <td>{{ $score->final_points }}</td>
                    <td>{{ $score->multiplier_applied > 1 ? $score->multiplier_applied . 'x' : '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <div class="footer">
            <p>Etigo Green Impact Report &middot; Every ride counts toward a greener future.</p>
        </div>
    </div>
</body>
</html>
