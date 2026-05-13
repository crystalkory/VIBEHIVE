<?php
// ads.php - Advertisement display component
function displayAds($pdo, $userId, $postCounter, &$adCounter) {
    // Get targeted ads
    $targetedAds = getTargetedAds($pdo, $userId, 50);
    $adCount = count($targetedAds);
    
    if ($adCount === 0) {
        return '';
    }
    
    $adIndex = $adCounter % $adCount;
    $ad = $targetedAds[$adIndex];
    $adCounter++;
    
    ob_start();
    ?>
    <!-- ADVERTISEMENT -->
    <div class="post ad-post" data-ad-id="<?= $ad['id'] ?>">
        <div class="ad-label">Sponsored</div>
        
        <div class="post-header">
            <img src="<?= htmlspecialchars($ad['profile_pic_url'] ?: 'default_profile.png') ?>"
                 alt="Advertiser" />
            <div class="user-info">
                <div class="username"><?= htmlspecialchars($ad['username']) ?></div>
                <div class="timestamp">Sponsored Ad</div>
            </div>
        </div>

        <div class="ad-header"><?= htmlspecialchars($ad['header']) ?></div>
        
        <div class="ad-description">
            <?= nl2br(htmlspecialchars($ad['description'])) ?>
        </div>

        <!-- Ad Media -->
        <?php if (!empty($ad['media_path'])): ?>
            <div class="ad-media-container">
                <?php if ($ad['ad_type'] === 'video'): ?>
                    <div class="ad-video-container">
                        <video class="ad-video" loop playsinline preload="auto" style="width: 100%; max-height: 400px; border-radius: 8px;">
                            <source src="<?= htmlspecialchars($ad['media_path']) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                        <div class="ad-video-overlay" onclick="toggleAdVideoPlayPause(this)"></div>
                        <div class="ad-video-controls">
                            <button class="ad-sound-toggle" onclick="toggleAdVideoSound(this)" data-muted="false">🔊</button>
                            <button class="ad-play-pause" onclick="toggleAdVideoPlayPause(this)">⏸️</button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $adImages = explode(',', $ad['media_path']);
                    $adImageCount = count($adImages);
                    $adFirstFour = array_slice($adImages, 0, 4);
                    $adExtraCount = $adImageCount - 4;
                    ?>
                    <div class="post-media">
                        <?php foreach ($adFirstFour as $index => $image):
                            $image = trim($image);
                        ?>
                            <div style="position:relative;">
                                <?php if ($index < 3): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                <?php elseif ($index === 3 && $adExtraCount > 0): ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                    <div class="overlay">
                                        +<?= $adExtraCount ?>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= htmlspecialchars($image) ?>" alt="Ad Image" />
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Call to Action Button -->
        <a href="<?= htmlspecialchars($ad['url']) ?>" target="_blank" class="cta-button">
            <?= htmlspecialchars($ad['cta_button']) ?>
        </a>

        <div class="actions">
            <span style="color: #888; font-size: 12px;">Advertisement</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Also include the helper functions needed for ads
function getTargetedAds($pdo, $userId, $limit = 20) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.profile_pic_url 
        FROM ads a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'active' 
        AND a.ends_at > CURRENT_TIMESTAMP
        ORDER BY a.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $allAds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $targetedAds = [];
    foreach ($allAds as $ad) {
        $locations = json_decode($ad['locations'], true) ?: [];
        if (userMatchesAdTargeting($pdo, $userId, $locations, $ad['gender_target'])) {
            $targetedAds[] = $ad;
        }
    }
    
    if (empty($targetedAds)) {
        return [];
    }
    
    // Store original ads in session for repetition
    if (!isset($_SESSION['original_ads'])) {
        $_SESSION['original_ads'] = $targetedAds;
    }
    
    // Check if we need to shuffle ads
    if (!isset($_SESSION['shuffled_ads']) || isset($_GET['refresh'])) {
        shuffle($targetedAds);
        $_SESSION['shuffled_ads'] = $targetedAds;
    } else {
        $targetedAds = $_SESSION['shuffled_ads'];
    }
    
    // If we need more ads than available, repeat the shuffled ads
    $availableAds = count($targetedAds);
    if ($availableAds > 0 && $limit > $availableAds) {
        $repeatedAds = [];
        $fullCycles = floor($limit / $availableAds);
        $remainder = $limit % $availableAds;
        
        for ($i = 0; $i < $fullCycles; $i++) {
            $repeatedAds = array_merge($repeatedAds, $targetedAds);
        }
        
        if ($remainder > 0) {
            $repeatedAds = array_merge($repeatedAds, array_slice($targetedAds, 0, $remainder));
        }
        
        return $repeatedAds;
    }
    
    return array_slice($targetedAds, 0, $limit);
}

function userMatchesAdTargeting($pdo, $userId, $adLocations, $adGender) {
    // Get user's country and gender
    $userCountry = getUserCountry($pdo, $userId);
    $userGender = getUserGender($pdo, $userId);
    
    // Check gender targeting
    if (!empty($adGender) && $adGender !== $userGender && $adGender !== '') {
        return false;
    }
    
    // Check if ad targets all countries
    if (count($adLocations) > 100) {
        return true;
    }
    
    // Check if user's country is directly targeted
    if (in_array($userCountry, $adLocations)) {
        return true;
    }
    
    // Check continent targeting
    $continents = [
        'Africa' => ['countries' => ['DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CV', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD', 'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE', 'LS', 'LR', 'LY', 'MG', 'MW', 'ML', 'MR', 'MU', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RW', 'ST', 'SN', 'SC', 'SL', 'SO', 'ZA', 'SS', 'SD', 'TZ', 'TG', 'TN', 'UG', 'ZM', 'ZW']],
        'Asia' => ['countries' => ['AF', 'AM', 'AZ', 'BH', 'BD', 'BT', 'BN', 'KH', 'CN', 'CY', 'GE', 'IN', 'ID', 'IR', 'IQ', 'IL', 'JP', 'JO', 'KZ', 'KW', 'KG', 'LA', 'LB', 'MY', 'MV', 'MN', 'MM', 'NP', 'KP', 'OM', 'PK', 'PH', 'QA', 'RU', 'SA', 'SG', 'KR', 'LK', 'SY', 'TW', 'TJ', 'TH', 'TR', 'TM', 'AE', 'UZ', 'VN', 'YE']],
        'Europe' => ['countries' => ['AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'XK', 'LV', 'LI', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT', 'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'UA', 'GB', 'VA']],
        'North America' => ['countries' => ['AG', 'BS', 'BB', 'BZ', 'CA', 'CR', 'CU', 'DM', 'DO', 'SV', 'GD', 'GT', 'HT', 'HN', 'JM', 'MX', 'NI', 'PA', 'KN', 'LC', 'VC', 'TT', 'US']],
        'South America' => ['countries' => ['AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'GY', 'PY', 'PE', 'SR', 'UY', 'VE']],
        'Oceania' => ['countries' => ['AU', 'FJ', 'KI', 'MH', 'FM', 'NR', 'NZ', 'PW', 'PG', 'WS', 'SB', 'TO', 'TV', 'VU']]
    ];
    
    foreach ($continents as $continentData) {
        $continentCountries = $continentData['countries'];
        $matchingCountries = array_intersect($continentCountries, $adLocations);
        if (!empty($matchingCountries) && in_array($userCountry, $continentCountries)) {
            return true;
        }
    }
    
    return false;
}

function getUserCountry($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT country FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function getUserGender($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}
?>