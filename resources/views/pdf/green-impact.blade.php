<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Green Impact Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #1a1a1a; padding: 40px; }
        .header { text-align: center; margin-bottom: 32px; border-bottom: 3px solid #1a6b3c; padding-bottom: 20px; }
        .header h1 { color: #1a6b3c; font-size: 28px; margin-bottom: 4px; }
        .header p { color: #666; font-size: 14px; }
        .stats-grid { display: flex; justify-content: space-between; margin-bottom: 32px; }
        .stat-card { background: #f0faf4; border: 1px solid #c6e9d3; border-radius: 8px; padding: 20px; text-align: center; width: 30%; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #1a6b3c; }
        .stat-card .label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .tier-badge { background: linear-gradient(135deg, #1a6b3c 0%, #2d9a5c 100%); color: #fff; border-radius: 12px; padding: 20px 28px; margin-bottom: 32px; display: flex; align-items: center; justify-content: space-between; }
        .tier-badge .tier-name { font-size: 22px; font-weight: 700; }
        .tier-badge .tier-points { font-size: 14px; opacity: 0.9; }
        h2 { color: #1a6b3c; font-size: 18px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th { background: #1a6b3c; color: #fff; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; }
        td { padding: 10px 12px; border-bottom: 1px solid #e5e5e5; font-size: 13px; }
        tr:nth-child(even) { background: #f9f9f9; }
        .footer { text-align: center; color: #999; font-size: 11px; margin-top: 32px; border-top: 1px solid #e5e5e5; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌿 Green Impact Report</h1>
        <p>{{ $user->first_name }} {{ $user->last_name }} &mdash; Generated {{ now()->format('d M Y') }}</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="value">{{ $totalRides }}</div>
            <div class="label">Total Rides</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($totalCo2Saved, 1) }}kg</div>
            <div class="label">CO₂ Saved</div>
        </div>
        <div class="stat-card">
            <div class="value">{{ number_format($electricKm, 1) }}km</div>
            <div class="label">Electric Kilometres</div>
        </div>
    </div>

    <div class="tier-badge">
        <div>
            <div class="tier-name">{{ $tierName }} Tier</div>
            <div class="tier-points">{{ number_format($totalPoints) }} ranking points</div>
        </div>
    </div>

    @if($recentScores->isNotEmpty())
    <h2>Recent Trip Impact</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Distance (km)</th>
                <th>CO₂ Saved (kg)</th>
                <th>Points Earned</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentScores as $score)
            <tr>
                <td>{{ $score->created_at->format('d M Y') }}</td>
                <td>{{ number_format($score->distance_km, 1) }}</td>
                <td>{{ number_format($score->co2_saved_kg, 2) }}</td>
                <td>{{ number_format($score->points_earned) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        <p>Etigo &mdash; Ride Green, Ride Smart</p>
        <p>This report reflects your environmental impact through eco-friendly ride choices.</p>
    </div>
</body>
</html>
