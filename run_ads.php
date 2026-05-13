<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

require_once "config.php";

// Define categories from signup.php
$categories = [
    'Entertainment', 'Dance', 'Lip-Sync', 'Comedy', 'Music', 
    'Beauty and Fashion', 'Food and Cooking', 'DIY and Crafting', 'Gaming'
];

// Comprehensive countries list organized by continent with continent pricing
$continents = [
    'Africa' => [
        'countries' => [
            'DZ' => 'Algeria', 'AO' => 'Angola', 'BJ' => 'Benin', 'BW' => 'Botswana', 'BF' => 'Burkina Faso',
            'BI' => 'Burundi', 'CV' => 'Cape Verde', 'CM' => 'Cameroon', 'CF' => 'Central African Republic',
            'TD' => 'Chad', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'DR Congo', 'DJ' => 'Djibouti',
            'EG' => 'Egypt', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
            'GA' => 'Gabon', 'GM' => 'Gambia', 'GH' => 'Ghana', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau',
            'KE' => 'Kenya', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'MG' => 'Madagascar',
            'MW' => 'Malawi', 'ML' => 'Mali', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MA' => 'Morocco',
            'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NE' => 'Niger', 'NG' => 'Nigeria', 'RW' => 'Rwanda',
            'ST' => 'São Tomé and Príncipe', 'SN' => 'Senegal', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
            'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan', 'SD' => 'Sudan', 'TZ' => 'Tanzania',
            'TG' => 'Togo', 'TN' => 'Tunisia', 'UG' => 'Uganda', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe'
        ],
        'price' => 40 // 54 countries (>50)
    ],
    'Asia' => [
        'countries' => [
            'AF' => 'Afghanistan', 'AM' => 'Armenia', 'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
            'BT' => 'Bhutan', 'BN' => 'Brunei', 'KH' => 'Cambodia', 'CN' => 'China', 'CY' => 'Cyprus',
            'GE' => 'Georgia', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
            'IL' => 'Israel', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KW' => 'Kuwait',
            'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'MY' => 'Malaysia', 'MV' => 'Maldives',
            'MN' => 'Mongolia', 'MM' => 'Myanmar', 'NP' => 'Nepal', 'KP' => 'North Korea', 'OM' => 'Oman',
            'PK' => 'Pakistan', 'PH' => 'Philippines', 'QA' => 'Qatar', 'RU' => 'Russia', 'SA' => 'Saudi Arabia',
            'SG' => 'Singapore', 'KR' => 'South Korea', 'LK' => 'Sri Lanka', 'SY' => 'Syria', 'TW' => 'Taiwan',
            'TJ' => 'Tajikistan', 'TH' => 'Thailand', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'AE' => 'United Arab Emirates',
            'UZ' => 'Uzbekistan', 'VN' => 'Vietnam', 'YE' => 'Yemen'
        ],
        'price' => 40 // 49 countries (>40 but <50)
    ],
    'Europe' => [
        'countries' => [
            'AL' => 'Albania', 'AD' => 'Andorra', 'AT' => 'Austria', 'BY' => 'Belarus', 'BE' => 'Belgium',
            'BA' => 'Bosnia and Herzegovina', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CY' => 'Cyprus',
            'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France',
            'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IE' => 'Ireland',
            'IT' => 'Italy', 'XK' => 'Kosovo', 'LV' => 'Latvia', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
            'LU' => 'Luxembourg', 'MT' => 'Malta', 'MD' => 'Moldova', 'MC' => 'Monaco', 'ME' => 'Montenegro',
            'NL' => 'Netherlands', 'MK' => 'North Macedonia', 'NO' => 'Norway', 'PL' => 'Poland', 'PT' => 'Portugal',
            'RO' => 'Romania', 'SM' => 'San Marino', 'RS' => 'Serbia', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
            'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'UA' => 'Ukraine', 'GB' => 'United Kingdom',
            'VA' => 'Vatican City'
        ],
        'price' => 30 // 45 countries (>40 but <50)
    ],
    'North America' => [
        'countries' => [
            'AG' => 'Antigua and Barbuda', 'BS' => 'Bahamas', 'BB' => 'Barbados', 'BZ' => 'Belize',
            'CA' => 'Canada', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
            'SV' => 'El Salvador', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'HT' => 'Haiti', 'HN' => 'Honduras',
            'JM' => 'Jamaica', 'MX' => 'Mexico', 'NI' => 'Nicaragua', 'PA' => 'Panama', 'KN' => 'Saint Kitts and Nevis',
            'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines', 'TT' => 'Trinidad and Tobago',
            'US' => 'United States'
        ],
        'price' => 20 // 23 countries (>20 but <30)
    ],
    'South America' => [
        'countries' => [
            'AR' => 'Argentina', 'BO' => 'Bolivia', 'BR' => 'Brazil', 'CL' => 'Chile', 'CO' => 'Colombia',
            'EC' => 'Ecuador', 'GY' => 'Guyana', 'PY' => 'Paraguay', 'PE' => 'Peru', 'SR' => 'Suriname',
            'UY' => 'Uruguay', 'VE' => 'Venezuela'
        ],
        'price' => 15 // 12 countries (>10 but <20)
    ],
    'Oceania' => [
        'countries' => [
            'AU' => 'Australia', 'FJ' => 'Fiji', 'KI' => 'Kiribati', 'MH' => 'Marshall Islands',
            'FM' => 'Micronesia', 'NR' => 'Nauru', 'NZ' => 'New Zealand', 'PW' => 'Palau',
            'PG' => 'Papua New Guinea', 'WS' => 'Samoa', 'SB' => 'Solomon Islands', 'TO' => 'Tonga',
            'TV' => 'Tuvalu', 'VU' => 'Vanuatu'
        ],
        'price' => 15 // 14 countries (>10 but <20)
    ]
];

// Flatten countries array for easy access
$all_countries = [];
foreach ($continents as $continent_data) {
    $all_countries = array_merge($all_countries, $continent_data['countries']);
}

// Days options
$days_options = [1, 2, 3, 7, 14, 30, 45, 60, 90, 150, 180, 200, 250, 300, 400, 500];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $header = trim($_POST['header'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $cta_button = trim($_POST['cta_button'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $locations = $_POST['locations'] ?? [];
    $continents_selected = $_POST['continents'] ?? [];
    $all_countries_option = isset($_POST['all_countries']) ? true : false;
    $gender = $_POST['gender'] ?? '';
    $days = (int)($_POST['days'] ?? 0);
    $target_categories = $_POST['target_categories'] ?? [];
    
    $ad_type = 'image';
    $media_path = '';
    $price_per_day = 0;
    
    // Check if editing existing ad
    $edit_ad_id = $_POST['edit_ad_id'] ?? null;
    
    // Validate required fields
    if (empty($header) || empty($description) || empty($cta_button) || empty($url) || $days <= 0) {
        $error = "Please fill all required fields";
    } elseif (!$all_countries_option && empty($locations) && empty($continents_selected)) {
        $error = "Please select at least one country, continent, or choose 'All Countries'";
    } else {
        // Handle file uploads
        $upload_dir = __DIR__ . '/ads_media/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Check if both video and image are uploaded
        $has_video = !empty($_FILES['video']['name']);
        $has_images = !empty($_FILES['images']['name'][0]);
        
        if ($has_video && $has_images) {
            $error = "Please upload either video OR images, not both";
        } elseif ($has_video) {
            // Handle video upload
            $video = $_FILES['video'];
            if ($video['size'] > 100 * 1024 * 1024) { // 100MB
                $error = "Video size must be less than 100MB";
            } else {
                $video_ext = pathinfo($video['name'], PATHINFO_EXTENSION);
                $video_filename = 'video_' . uniqid() . '.' . $video_ext;
                $video_path = $upload_dir . $video_filename;
                
                if (move_uploaded_file($video['tmp_name'], $video_path)) {
                    $media_path = 'ads_media/' . $video_filename;
                    $ad_type = 'video';
                } else {
                    $error = "Failed to upload video";
                }
            }
        } elseif ($has_images) {
            // Handle image uploads
            $image_paths = [];
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $image_ext = pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION);
                    $image_filename = 'image_' . uniqid() . '.' . $image_ext;
                    $image_path = $upload_dir . $image_filename;
                    
                    if (move_uploaded_file($tmp_name, $image_path)) {
                        $image_paths[] = 'ads_media/' . $image_filename;
                    }
                }
            }
            
            if (!empty($image_paths)) {
                $media_path = implode(',', $image_paths);
                $ad_type = 'image';
            } else {
                $error = "Failed to upload images";
            }
        } else {
            // Check if this is a repost (no new files uploaded)
            if (!$edit_ad_id) {
                $error = "Please upload either a video or at least one image";
            }
        }
        
        if (!isset($error)) {
            // Build final locations array and calculate price
            $final_locations = [];
            $price_per_day = 0;
            
            if ($all_countries_option) {
                // All countries selected - $100 per day
                $price_per_day = $ad_type === 'video' ? 151 : 150;
                $final_locations = array_keys($all_countries);
            } elseif (!empty($continents_selected)) {
                // Calculate continent pricing
                $continent_price = 0;
                foreach ($continents_selected as $continent) {
                    if (isset($continents[$continent])) {
                        $continent_price += $continents[$continent]['price'];
                        $final_locations = array_merge($final_locations, array_keys($continents[$continent]['countries']));
                    }
                }
                
                // Add individual country selections
                $final_locations = array_merge($final_locations, $locations);
                $final_locations = array_unique($final_locations);
                
                // Calculate individual country pricing
                $individual_countries_count = count($locations);
                $individual_country_price = 0;
                
                if ($individual_countries_count > 0) {
                    if ($individual_countries_count <= 10) {
                        $individual_country_price = $individual_countries_count * 5;
                    } elseif ($individual_countries_count <= 30) {
                        $individual_country_price = $individual_countries_count * 6;
                    } else {
                        $individual_country_price = $individual_countries_count * 7;
                    }
                }
                
                // Total price per day
                $price_per_day = $continent_price + $individual_country_price;
                
                // Add video premium
                if ($ad_type === 'video') {
                    $price_per_day += 1; // $1 extra per day for video
                }
            } else {
                // Only individual countries selected
                $individual_countries_count = count($locations);
                
                if ($individual_countries_count <= 10) {
                    $price_per_day = $individual_countries_count * 5;
                } elseif ($individual_countries_count <= 30) {
                    $price_per_day = $individual_countries_count * 6;
                } else {
                    $price_per_day = $individual_countries_count * 7;
                }
                
                // Add video premium
                if ($ad_type === 'video') {
                    $price_per_day += 1; // $1 extra per day for video
                }
                
                $final_locations = $locations;
            }
            
            $total_price = $days * $price_per_day;
            
            // Store ad data in session for payment page
            $_SESSION['ad_data'] = [
                'header' => $header,
                'description' => $description,
                'cta_button' => $cta_button,
                'url' => $url,
                'locations' => $final_locations,
                'continents' => $continents_selected,
                'all_countries' => $all_countries_option,
                'gender' => $gender,
                'days' => $days,
                'ad_type' => $ad_type,
                'media_path' => $media_path,
                'price_per_day' => $price_per_day,
                'total_price' => $total_price,
                'edit_ad_id' => $edit_ad_id,
                'target_categories' => $target_categories
            ];
            
            header('Location: ads_payment.php');
            exit;
        }
    }
}

// Check if reposting an ad
$repost_ad_id = $_GET['repost'] ?? null;
$ad_to_repost = null;
if ($repost_ad_id) {
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ? AND user_id = ?");
    $stmt->execute([$repost_ad_id, $_SESSION['user_id']]);
    $ad_to_repost = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ad_to_repost) {
        $ad_to_repost['locations'] = json_decode($ad_to_repost['locations'], true);
        $ad_to_repost['target_categories'] = json_decode($ad_to_repost['target_categories'], true);
        // Check if this was an "All Countries" ad
        $all_countries_in_ad = count($ad_to_repost['locations']) === count($all_countries);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ad</title>
    <style>
       body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
}

.container {
    max-width: 900px;
    margin: 0 auto;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    padding: 40px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

h1 {
    text-align: center;
    color: #2d3748;
    margin-bottom: 30px;
    font-size: 32px;
    font-weight: 800;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.form-group {
    margin-bottom: 25px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 700;
    color: #2d3748;
    font-size: 16px;
}

input[type="text"],
input[type="url"],
textarea,
select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 12px;
    font-size: 16px;
    box-sizing: border-box;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    font-family: inherit;
}

input[type="text"]:focus,
input[type="url"]:focus,
textarea:focus,
select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    background: white;
}

textarea {
    height: 120px;
    resize: vertical;
    line-height: 1.5;
}

.file-upload {
    border: 2px dashed rgba(102, 126, 234, 0.3);
    padding: 25px;
    text-align: center;
    border-radius: 15px;
    margin-bottom: 15px;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    cursor: pointer;
}

.file-upload:hover {
    border-color: #667eea;
    background: rgba(102, 126, 234, 0.05);
}

.file-upload label {
    color: #667eea;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 10px;
    display: block;
}

.file-upload input[type="file"] {
    margin: 0 auto;
}

.price-display {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 25px;
    border-radius: 15px;
    text-align: center;
    font-size: 24px;
    font-weight: 800;
    margin: 30px 0;
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 15px 30px;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-size: 18px;
    font-weight: 700;
    width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
    background: linear-gradient(135deg, #764ba2, #667eea);
}

.error {
    background: rgba(245, 101, 101, 0.1);
    color: #e53e3e;
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 25px;
    border: 1px solid rgba(245, 101, 101, 0.3);
    font-weight: 600;
    text-align: center;
}

.preview-image {
    max-width: 100px;
    max-height: 100px;
    margin: 8px;
    border-radius: 8px;
    border: 2px solid rgba(102, 126, 234, 0.3);
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.targeting-option {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(102, 126, 234, 0.3);
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.targeting-option:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-color: #667eea;
}

.targeting-option.selected {
    background: rgba(102, 126, 234, 0.1);
    border-color: #667eea;
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.2);
}

.price-breakdown {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 12px;
    margin-top: 15px;
    font-size: 14px;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.price-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    padding: 5px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.price-item:last-child {
    border-bottom: none;
}

.price-warning {
    color: #e53e3e;
    font-weight: 700;
    margin-top: 8px;
    font-size: 14px;
}

.continent-section {
    margin-bottom: 20px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
}

.continent-header {
    background: rgba(102, 126, 234, 0.1);
    padding: 15px 20px;
    cursor: pointer;
    font-weight: 700;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #2d3748;
    transition: all 0.3s ease;
}

.continent-header:hover {
    background: rgba(102, 126, 234, 0.2);
}

.continent-content {
    padding: 20px;
    background: white;
    display: none;
}

.continent-content.show {
    display: block;
}

.countries-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
    margin-top: 15px;
}

.country-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.country-checkbox:hover {
    background: rgba(102, 126, 234, 0.05);
}

.country-checkbox input[type="checkbox"] {
    transform: scale(1.2);
    accent-color: #667eea;
}

.selected-countries {
    margin-top: 15px;
    font-size: 14px;
    color: #666;
    font-weight: 600;
    padding: 10px;
    background: rgba(102, 126, 234, 0.05);
    border-radius: 8px;
}

.continents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.continent-checkbox {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.continent-checkbox:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-color: rgba(102, 126, 234, 0.3);
}

.continent-checkbox.selected {
    background: rgba(102, 126, 234, 0.1);
    border-color: #667eea;
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.2);
}

.continent-price {
    margin-left: auto;
    font-weight: 800;
    color: #48bb78;
    font-size: 16px;
}

.targeting-tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 2px solid rgba(0, 0, 0, 0.1);
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 12px 12px 0 0;
    padding: 0 10px;
}

.targeting-tab {
    padding: 15px 25px;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    font-weight: 600;
    color: #666;
    transition: all 0.3s ease;
}

.targeting-tab:hover {
    color: #667eea;
}

.targeting-tab.active {
    border-bottom-color: #667eea;
    color: #667eea;
    font-weight: 700;
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.pricing-info {
    background: rgba(255, 243, 205, 0.8);
    backdrop-filter: blur(10px);
    border-left: 4px solid #ffc107;
    padding: 15px 20px;
    margin: 15px 0;
    border-radius: 8px;
    font-size: 14px;
    line-height: 1.5;
}

.breakdown-section {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px dashed rgba(0, 0, 0, 0.2);
}

.categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
    margin-top: 15px;
}

.category-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.category-checkbox:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-color: rgba(102, 126, 234, 0.3);
}

.category-checkbox.selected {
    background: rgba(102, 126, 234, 0.1);
    border-color: #667eea;
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.2);
}

.category-checkbox strong {
    color: #2d3748;
    font-weight: 600;
}

.targeting-section {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(10px);
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 25px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 20px;
    color: #2d3748;
    border-bottom: 3px solid #667eea;
    padding-bottom: 8px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Responsive Design */
@media (max-width: 768px) {
    body {
        padding: 15px;
    }
    
    .container {
        padding: 25px 20px;
    }
    
    h1 {
        font-size: 28px;
    }
    
    .targeting-tabs {
        flex-direction: column;
    }
    
    .targeting-tab {
        text-align: center;
        border-bottom: none;
        border-right: 3px solid transparent;
        margin-bottom: 5px;
    }
    
    .targeting-tab.active {
        border-bottom: none;
        border-right-color: #667eea;
    }
    
    .countries-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
    
    .continents-grid {
        grid-template-columns: 1fr;
    }
    
    .categories-grid {
        grid-template-columns: 1fr;
    }
    
    .price-display {
        font-size: 20px;
        padding: 20px;
    }
    
    .btn {
        padding: 12px 25px;
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }
    
    .container {
        padding: 20px 15px;
    }
    
    h1 {
        font-size: 24px;
    }
    
    .file-upload {
        padding: 15px;
    }
    
    .targeting-section {
        padding: 20px 15px;
    }
    
    .countries-grid {
        grid-template-columns: 1fr;
    }
    
    .continent-checkbox {
        padding: 15px;
    }
    
    .category-checkbox {
        padding: 12px;
    }
    
    .price-display {
        font-size: 18px;
        padding: 15px;
    }
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #764ba2, #667eea);
}

/* Animation for form elements */
.form-group {
    animation: slideInUp 0.5s ease;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Stagger animation for form groups */
.form-group:nth-child(1) { animation-delay: 0.1s; }
.form-group:nth-child(2) { animation-delay: 0.2s; }
.form-group:nth-child(3) { animation-delay: 0.3s; }
.form-group:nth-child(4) { animation-delay: 0.4s; }
.form-group:nth-child(5) { animation-delay: 0.5s; }
.form-group:nth-child(6) { animation-delay: 0.6s; }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= $repost_ad_id ? 'Repost Ad' : 'Create New Ad' ?></h1>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="adForm">
            <input type="hidden" name="edit_ad_id" value="<?= $repost_ad_id ?>">
            
            <div class="form-group">
                <label for="header">Ad Header *</label>
                <input type="text" id="header" maxlength="30" name="header" required 
                       value="<?= htmlspecialchars($ad_to_repost['header'] ?? ($_POST['header'] ?? '')) ?>">
            </div>
            
            <div class="form-group">
                <label for="description">Description *</label>
                <textarea id="description" maxlength="100" name="description" required><?= htmlspecialchars($ad_to_repost['description'] ?? ($_POST['description'] ?? '')) ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Upload Video (Max 100MB) OR Images</label>
                <div class="file-upload">
                    <label for="video">Choose Video:</label>
                    <input type="file" id="video" name="video" accept="video/*">
                </div>
                <div id="filePreview"></div>
            </div>
            
            <div class="form-group">
                <label for="cta_button">Call to Action Button Text *</label>
                <input type="text" id="cta_button" name="cta_button" required 
                       value="<?= htmlspecialchars($ad_to_repost['cta_button'] ?? ($_POST['cta_button'] ?? '')) ?>">
            </div>
            
            <div class="form-group">
                <label for="url">Destination URL *</label>
                <input type="url" id="url" name="url" required 
                       value="<?= htmlspecialchars($ad_to_repost['url'] ?? ($_POST['url'] ?? '')) ?>">
            </div>

            <!-- Category Targeting Section -->
            <div class="targeting-section">
                <div class="section-title">🎯 Category Targeting</div>
                <p>Select the categories where you want your ad to be displayed:</p>
                <div class="categories-grid">
                    <?php foreach ($categories as $category): ?>
                        <div class="category-checkbox" onclick="toggleCategory('<?= $category ?>')">
                            <input type="checkbox" name="target_categories[]" value="<?= $category ?>" 
                                   id="category_<?= $category ?>" style="display: none;"
                                   <?= (isset($ad_to_repost['target_categories']) && in_array($category, $ad_to_repost['target_categories'])) || (isset($_POST['target_categories']) && in_array($category, $_POST['target_categories'])) ? 'checked' : '' ?>>
                            <strong><?= htmlspecialchars($category) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="selected-countries" id="selectedCategories">
                    Selected: <span id="selectedCategoriesCount">0</span> categories
                </div>
                <div class="pricing-info">
                    <strong>Category Targeting:</strong><br>
                    • Target users interested in specific content categories<br>
                    • Increases ad relevance and engagement<br>
                    • No additional cost for category targeting
                </div>
            </div>
            
            <div class="form-group">
                <label>Target Locations *</label>
                
                <!-- Global Options -->
                <div class="targeting-option" id="allCountriesOption" onclick="toggleAllCountries()">
                    <input type="checkbox" name="all_countries" id="all_countries" style="display: none;" 
                           <?= (isset($all_countries_in_ad) && $all_countries_in_ad) || (isset($_POST['all_countries']) && $_POST['all_countries']) ? 'checked' : '' ?>>
                    <strong>🌍 Run in All Countries</strong>
                    <p>Display your ad to users in all available countries worldwide</p>
                    <div class="price-warning">$100 per day for image ads • $101 per day for video ads</div>
                </div>
                
                <div id="specificTargeting" style="<?= (isset($all_countries_in_ad) && $all_countries_in_ad) || (isset($_POST['all_countries']) && $_POST['all_countries']) ? 'display: none;' : '' ?>">
                    <!-- Targeting Tabs -->
                    <div class="targeting-tabs">
                        <div class="targeting-tab active" onclick="showTab('continents')">🌐 Continents</div>
                        <div class="targeting-tab" onclick="showTab('countries')">📍 Individual Countries</div>
                    </div>
                    
                    <!-- Continents Tab -->
                    <div id="continentsTab" class="tab-content active">
                        <p>Select entire continents (prices vary by continent size):</p>
                        <div class="continents-grid">
                            <?php foreach ($continents as $continent_name => $continent_data): ?>
                                <div class="continent-checkbox" onclick="toggleContinent('<?= $continent_name ?>')">
                                    <input type="checkbox" name="continents[]" value="<?= $continent_name ?>" 
                                           id="continent_<?= $continent_name ?>" style="display: none;"
                                           <?= (isset($_POST['continents']) && in_array($continent_name, $_POST['continents'])) ? 'checked' : '' ?>>
                                    <strong><?= $continent_name ?></strong>
                                    <span style="color: #666; font-size: 12px;">(<?= count($continent_data['countries']) ?> countries)</span>
                                    <div class="continent-price">$<?= $continent_data['price'] ?>/day</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="pricing-info">
                            <strong>Continent Pricing:</strong><br>
                            • 50+ countries: $40/day<br>
                            • 40-49 countries: $30/day<br>
                            • 20-29 countries: $20/day<br>
                            • 10-19 countries: $15/day
                        </div>
                    </div>
                    
                    <!-- Countries Tab -->
                    <div id="countriesTab" class="tab-content">
                        <p>Or select specific countries:</p>
                        <?php foreach ($continents as $continent_name => $continent_data): ?>
                            <div class="continent-section">
                                <div class="continent-header" onclick="toggleContinentSection('<?= $continent_name ?>')">
                                    <span><?= $continent_name ?> (<?= count($continent_data['countries']) ?> countries)</span>
                                    <span>▼</span>
                                </div>
                                <div class="continent-content" id="content_<?= $continent_name ?>">
                                    <div class="countries-grid">
                                        <?php foreach ($continent_data['countries'] as $code => $name): ?>
                                            <div class="country-checkbox">
                                                <input type="checkbox" name="locations[]" value="<?= $code ?>" 
                                                       id="country_<?= $code ?>"
                                                       <?= (isset($ad_to_repost['locations']) && in_array($code, $ad_to_repost['locations'])) || (isset($_POST['locations']) && in_array($code, $_POST['locations'])) ? 'checked' : '' ?>>
                                                <label for="country_<?= $code ?>"><?= htmlspecialchars($name) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="selected-countries" id="selectedCountries">
                            Selected: <span id="selectedCount">0</span> countries
                        </div>
                        <div class="pricing-info">
                            <strong>Individual Country Pricing:</strong><br>
                            • 1-10 countries: $5/day per country<br>
                            • 11-30 countries: $6/day per country<br>
                            • 31+ countries: $7/day per country<br>
                            • Video ads: +$1/day extra
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="gender">Target Gender</label>
                <select id="gender" name="gender">
                    <option value="">All Genders</option>
                    <option value="male" <?= ($ad_to_repost['gender'] ?? ($_POST['gender'] ?? '')) === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= ($ad_to_repost['gender'] ?? ($_POST['gender'] ?? '')) === 'female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="days">Duration (Days) *</label>
                <select id="days" name="days" required>
                    <option value="">Select Days</option>
                    <?php foreach ($days_options as $day): ?>
                        <option value="<?= $day ?>" <?= ($ad_to_repost['days'] ?? ($_POST['days'] ?? 0)) == $day ? 'selected' : '' ?>>
                            <?= $day ?> days
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="price-display" id="priceDisplay">
                Total Price: $<span id="totalPrice">0</span>
                <div class="price-breakdown" id="priceBreakdown" style="display: none;">
                    <div class="price-item">
                        <span>Base price:</span>
                        <span>$<span id="basePrice">0</span>/day</span>
                    </div>
                    <div class="price-item">
                        <span>Duration:</span>
                        <span><span id="durationDays">0</span> days</span>
                    </div>
                    <div id="breakdownDetails"></div>
                    <div class="price-item" style="font-weight: bold; border-top: 1px solid #ccc; padding-top: 5px;">
                        <span>Total:</span>
                        <span>$<span id="breakdownTotal">0</span></span>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn">Continue to Payment</button>
        </form>
    </div>

    <script>
        const daysSelect = document.getElementById('days');
        const priceDisplay = document.getElementById('totalPrice');
        const videoInput = document.getElementById('video');
        const imagesInput = document.getElementById('images');
        const filePreview = document.getElementById('filePreview');
        const countryCheckboxes = document.querySelectorAll('input[name="locations[]"]');
        const continentCheckboxes = document.querySelectorAll('input[name="continents[]"]');
        const categoryCheckboxes = document.querySelectorAll('input[name="target_categories[]"]');
        const selectedCount = document.getElementById('selectedCount');
        const selectedCategoriesCount = document.getElementById('selectedCategoriesCount');
        const allCountriesCheckbox = document.getElementById('all_countries');
        const allCountriesOption = document.getElementById('allCountriesOption');
        const specificTargeting = document.getElementById('specificTargeting');
        const priceBreakdown = document.getElementById('priceBreakdown');
        const basePriceElement = document.getElementById('basePrice');
        const durationDaysElement = document.getElementById('durationDays');
        const breakdownTotalElement = document.getElementById('breakdownTotal');
        const breakdownDetails = document.getElementById('breakdownDetails');
        
        // Continent pricing data
        const continentPrices = {
            'Africa': 40,
            'Asia': 40,
            'Europe': 30,
            'North America': 20,
            'South America': 15,
            'Oceania': 15
        };
        
        let selectedCountries = new Set();
        let selectedContinents = new Set();
        let selectedCategories = new Set();
        let isAllCountries = false;
        let currentTab = 'continents';
        let isVideo = false;
        
        function calculatePrice() {
            const days = parseInt(daysSelect.value) || 0;
            let totalPricePerDay = 0;
            let breakdownHTML = '';
            
            if (isAllCountries) {
                // All countries - $100 per day
                totalPricePerDay = isVideo ? 151 : 150;
                breakdownHTML = `
                    <div class="breakdown-section">
                        <div class="price-item"><span>All Countries:</span><span>$${isVideo ? '151' : '150'}/day</span></div>
                    </div>
                `;
            } else if (selectedContinents.size > 0 || selectedCountries.size > 0) {
                // Calculate continent pricing
                let continentTotal = 0;
                selectedContinents.forEach(continent => {
                    continentTotal += continentPrices[continent] || 0;
                });
                
                // Calculate individual country pricing
                let countryTotal = 0;
                const countryCount = selectedCountries.size;
                
                if (countryCount > 0) {
                    if (countryCount <= 10) {
                        countryTotal = countryCount * 5;
                    } else if (countryCount <= 30) {
                        countryTotal = countryCount * 6;
                    } else {
                        countryTotal = countryCount * 7;
                    }
                }
                
                totalPricePerDay = continentTotal + countryTotal;
                
                // Add video premium
                if (isVideo) {
                    totalPricePerDay += 1;
                }
                
                // Build breakdown HTML
                breakdownHTML = '<div class="breakdown-section">';
                
                if (selectedContinents.size > 0) {
                    breakdownHTML += '<div class="price-item"><span>Continents:</span><span>$' + continentTotal + '/day</span></div>';
                    selectedContinents.forEach(continent => {
                        breakdownHTML += `<div class="price-item" style="padding-left: 20px;"><span>${continent}:</span><span>$${continentPrices[continent]}/day</span></div>`;
                    });
                }
                
                if (selectedCountries.size > 0) {
                    let countryRate = 5;
                    if (countryCount > 10) countryRate = 6;
                    if (countryCount > 30) countryRate = 7;
                    
                    breakdownHTML += `<div class="price-item"><span>Individual Countries (${countryCount}):</span><span>$${countryTotal}/day</span></div>`;
                    breakdownHTML += `<div class="price-item" style="padding-left: 20px;"><span>Rate:</span><span>$${countryRate}/country/day</span></div>`;
                }
                
                if (isVideo) {
                    breakdownHTML += '<div class="price-item"><span>Video Premium:</span><span>$1/day</span></div>';
                }
                
                breakdownHTML += '</div>';
            }
            
            const total = days * totalPricePerDay;
            priceDisplay.textContent = total.toFixed(2);
            
            // Update price breakdown
            if (days > 0 && totalPricePerDay > 0) {
                basePriceElement.textContent = totalPricePerDay.toFixed(2);
                durationDaysElement.textContent = days;
                breakdownTotalElement.textContent = total.toFixed(2);
                breakdownDetails.innerHTML = breakdownHTML;
                priceBreakdown.style.display = 'block';
            } else {
                priceBreakdown.style.display = 'none';
            }
        }
        
        function updateFilePreview() {
            filePreview.innerHTML = '';
            isVideo = false;
            
            if (videoInput.files.length > 0) {
                isVideo = true;
                const file = videoInput.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    filePreview.innerHTML = `<p>Video selected: ${file.name}</p>`;
                };
                reader.readAsDataURL(file);
            } else if (imagesInput.files.length > 0) {
                filePreview.innerHTML = '<p>Images selected:</p>';
                Array.from(imagesInput.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'preview-image';
                        filePreview.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            }
            calculatePrice();
        }
        
        function updateSelectedCountries() {
            selectedCountries.clear();
            countryCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedCountries.add(checkbox.value);
                }
            });
            selectedCount.textContent = selectedCountries.size;
            calculatePrice();
        }
        
        function updateSelectedContinents() {
            selectedContinents.clear();
            continentCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedContinents.add(checkbox.value);
                }
            });
            calculatePrice();
        }
        
        function updateSelectedCategories() {
            selectedCategories.clear();
            categoryCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedCategories.add(checkbox.value);
                }
            });
            selectedCategoriesCount.textContent = selectedCategories.size;
        }
        
        function toggleCategory(categoryName) {
            const checkbox = document.getElementById('category_' + categoryName);
            const categoryDiv = checkbox.closest('.category-checkbox');
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                categoryDiv.classList.add('selected');
            } else {
                categoryDiv.classList.remove('selected');
            }
            
            updateSelectedCategories();
        }
        
        function toggleAllCountries() {
            isAllCountries = !isAllCountries;
            allCountriesCheckbox.checked = isAllCountries;
            
            if (isAllCountries) {
                allCountriesOption.classList.add('selected');
                specificTargeting.style.display = 'none';
                // Uncheck all other selections
                countryCheckboxes.forEach(checkbox => checkbox.checked = false);
                continentCheckboxes.forEach(checkbox => checkbox.checked = false);
                updateSelectedCountries();
                updateSelectedContinents();
            } else {
                allCountriesOption.classList.remove('selected');
                specificTargeting.style.display = 'block';
            }
            
            calculatePrice();
        }
        
        function toggleContinent(continentName) {
            const checkbox = document.getElementById('continent_' + continentName);
            const continentDiv = checkbox.closest('.continent-checkbox');
            
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                continentDiv.classList.add('selected');
            } else {
                continentDiv.classList.remove('selected');
            }
            
            updateSelectedContinents();
        }
        
        function toggleContinentSection(continentName) {
            const content = document.getElementById('content_' + continentName);
            content.classList.toggle('show');
        }
        
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.targeting-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.currentTarget.classList.add('active');
            currentTab = tabName;
        }
        
        // Event listeners
        daysSelect.addEventListener('change', calculatePrice);
        videoInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                imagesInput.value = '';
            }
            updateFilePreview();
        });
        imagesInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                videoInput.value = '';
            }
            updateFilePreview();
        });
        
        countryCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedCountries();
                // If user selects any country, uncheck "All Countries"
                if (this.checked && isAllCountries) {
                    toggleAllCountries();
                }
            });
        });
        
        continentCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedContinents();
                // If user selects any continent, uncheck "All Countries"
                if (this.checked && isAllCountries) {
                    toggleAllCountries();
                }
            });
        });
        
        categoryCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedCategories();
            });
        });
        
        // Form validation
        document.getElementById('adForm').addEventListener('submit', function(e) {
            if (!isAllCountries && selectedCountries.size === 0 && selectedContinents.size === 0) {
                e.preventDefault();
                alert('Please select at least one country, continent, or choose "All Countries"');
                return;
            }
        });
        
        // Initialize
        <?php if (isset($all_countries_in_ad) && $all_countries_in_ad): ?>
        isAllCountries = true;
        allCountriesOption.classList.add('selected');
        <?php endif; ?>
        
        updateSelectedCountries();
        updateSelectedContinents();
        updateSelectedCategories();
        calculatePrice();
    </script>
</body>
</html>