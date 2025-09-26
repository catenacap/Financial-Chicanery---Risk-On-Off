<?php
// ==================== ERROR DISPLAY (your standard) ====================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

// ==================== CONFIG ====================
$DB = [
  'host' => '',
  'user' => '',
  'pass' => '',
  'name' => '',
  'tbl_crude' => 'crude_usd', // date, value
  'tbl_vix'   => 'vix_usd',   // date, value
];

$RAPID_HOST = 'yahoo-finance15.p.rapidapi.com';
$RAPID_KEY  = '';
$FX_INTERVAL = '1mo';
$FX_SYMBOLS = [
  'USDJPY' => 'JPY%3DX',       // Yahoo JPY=X is USD/JPY
  'GBPUSD' => 'GBPUSD%3DX',    // Yahoo GBPUSD=X
  'EURUSD' => 'EURUSD%3DX',    // Yahoo EURUSD=X
];

// Interpretation toggle for USDJPY-1
$USE_INVERSE_USDJPY = isset($_GET['inverse_jpy']) ? (intval($_GET['inverse_jpy']) === 1) : true;

// Optional date window
$FROM = isset($_GET['from']) ? $_GET['from'] : null; // 'YYYY-MM-DD'
$TO   = isset($_GET['to'])   ? $_GET['to']   : null;

// Scaling constants per your expression
$K_CRUDE = 2.0;
$K_USDEUR = 0.576;
$K_VIX = 0.000001;
$K_DEN = 24000000.0;

// ==================== Gauge Params (GET with Pine defaults) ====================
$mode        = $_GET['mode']        ?? 'BDO_PCTL'; // TGO_PERCRANK | BDO_PCTL | BDO_EMA
$len         = max(1, intval($_GET['len'] ?? 20));         // SMA for base
$momLen      = max(1, intval($_GET['momLen'] ?? 10));
$momSmooth   = max(1, intval($_GET['momSmooth'] ?? 3));
$momWeight   = floatval($_GET['momWeight'] ?? 0.5);

$drEnabled   = isset($_GET['drEnabled']) ? (intval($_GET['drEnabled']) === 1) : 1;
$drModeExp   = isset($_GET['drModeExp']) ? (intval($_GET['drModeExp']) === 1) : 1; // 1=exp, 0=linear
$drHalfYrs   = max(0.1, floatval($_GET['drHalfYrs'] ?? 8.0));
$drBeta      = max(0.0, floatval($_GET['drBeta'] ?? 0.15));
$drBase      = isset($_GET['drBase']) ? (intval($_GET['drBase']) === 1) : 0;

$bdoOn       = isset($_GET['bdoOn']) ? (intval($_GET['bdoOn']) === 1) : 1;
$bdoQLow     = max(0.0, min(50.0, floatval($_GET['bdoQLow'] ?? 10.0)));
$bdoQHigh    = max(50.0, min(100.0, floatval($_GET['bdoQHigh'] ?? 90.0)));
$bdoBlendW   = max(0.0, min(1.0, floatval($_GET['bdoBlendW'] ?? 0.0)));
$bdoWin      = max(50, intval($_GET['bdoWin'] ?? 120));
$posLenFrac  = max(2, intval($_GET['posLenFrac'] ?? 5));

$normWin     = max(50, intval($_GET['normWin'] ?? 120));
$warmWin     = max(5,  intval($_GET['warmWin'] ?? 20));

// ==================== HELPERS ====================
function curl_json($url, $headers) {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => $headers,
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException("cURL error: $err"); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP $code: $resp");
  $json = json_decode($resp, true);
  if (!is_array($json)) throw new RuntimeException("Invalid JSON");
  return $json;
}

function mysql_conn($DB) {
  $mysqli = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['name']);
  if ($mysqli->connect_error) throw new RuntimeException("DB connection failed: " . $mysqli->connect_error);
  $mysqli->set_charset('utf8mb4');
  return $mysqli;
}

function load_table_kv($mysqli, $table) {
  $q = "SELECT date, value FROM `$table` ORDER BY date ASC";
  $res = $mysqli->query($q);
  if (!$res) throw new RuntimeException("Query failed: ".$mysqli->error);
  $arr = [];
  while ($row = $res->fetch_assoc()) {
    $d = substr($row['date'],0,10);
    $arr[$d] = floatval($row['value']);
  }
  return $arr;
}

// carry-forward (value at or before date)
function value_on_or_before($map, $dateYmd) {
  if (isset($map[$dateYmd])) return $map[$dateYmd];
  static $cache = [];
  $hash = md5(json_encode(array_keys($map))); // cache by keys
  if (!isset($cache[$hash])) {
    $dates = array_keys($map);
    sort($dates);
    $cache[$hash] = $dates;
  }
  $dates = $cache[$hash];
  $lo = 0; $hi = count($dates)-1; $ans = null;
  while ($lo <= $hi) {
    $mid = intdiv($lo+$hi,2);
    if ($dates[$mid] <= $dateYmd) { $ans = $dates[$mid]; $lo = $mid+1; }
    else { $hi = $mid-1; }
  }
  return $ans ? $map[$ans] : null;
}

function within_range($date, $FROM, $TO) {
  if ($FROM && $date < $FROM) return false;
  if ($TO && $date > $TO) return false;
  return true;
}

// Simple SMA over trailing window inclusive (requires $i >= $win-1)
function sma_at($arr, $i, $win) {
  $sum = 0.0;
  for ($k = $i-$win+1; $k <= $i; $k++) $sum += $arr[$k];
  return $sum / $win;
}

// Simple EMA over trailing sequence ending at i; alpha = 2/(n+1)
function ema_series($values, $n) {
  $out = [];
  if ($n <= 1) return $values;
  $alpha = 2.0 / ($n + 1.0);
  $ema = $values[0];
  $out[0] = $ema;
  $N = count($values);
  for ($i=1; $i<$N; $i++) {
    $ema = $alpha * $values[$i] + (1.0 - $alpha) * $ema;
    $out[$i] = $ema;
  }
  return $out;
}

// Percent rank of last value within window [i-win+1..i], returns 0..100
function percent_rank_at($arr, $i, $win) {
  $start = $i - $win + 1;
  if ($start < 0) return null;
  $slice = array_slice($arr, $start, $win);
  $x = $arr[$i];
  $cnt = 0;
  foreach ($slice as $v) if ($v <= $x) $cnt++;
  return 100.0 * ($cnt - 1) / max(1, $win - 1);
}

// Linear regression y at current bar over window (like Pine ta.linreg(x, len, 0))
function linreg_current($arr, $i, $win) {
  $start = $i - $win + 1;
  if ($start < 0) return null;
  $xsum=0; $ysum=0; $x2sum=0; $xysum=0;
  for ($k=0; $k<$win; $k++) {
    $x = $k;
    $y = $arr[$start+$k];
    $xsum += $x; $ysum += $y; $x2sum += $x*$x; $xysum += $x*$y;
  }
  $n = $win;
  $den = ($n*$x2sum - $xsum*$xsum);
  if ($den == 0.0) return null;
  $slope = ($n*$xysum - $xsum*$ysum) / $den;
  $inter = ($ysum - $slope*$xsum) / $n;
  return $inter + $slope * ($win - 1);
}

// Percentile with linear interpolation over window
function percentile_at($arr, $i, $win, $q) {
  $start = $i - $win + 1;
  if ($start < 0) return null;
  $slice = array_slice($arr, $start, $win);
  sort($slice);
  $p = max(0.0, min(100.0, $q));
  $rank = ($p/100.0) * (count($slice)-1);
  $lo = (int)floor($rank);
  $hi = (int)ceil($rank);
  if ($lo == $hi) return $slice[$lo];
  $w = $rank - $lo;
  return (1.0-$w)*$slice[$lo] + $w*$slice[$hi];
}

/* ======== Multi-asset helpers (namespaced mm_*) ======== */
function mm_load_table(mysqli $mysqli, string $table): array {
  $q = "SELECT date, value FROM `$table` ORDER BY date ASC";
  $res = $mysqli->query($q);
  if (!$res) throw new RuntimeException("Query failed ($table): ".$mysqli->error);
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $d = substr($row['date'],0,10);
    $v = floatval($row['value']);
    if (is_finite($v)) $out[$d] = $v;
  }
  return $out;
}
function mm_month_end(string $ymd): string {
  $t = strtotime($ymd);
  $y = intval(date('Y',$t)); $m = intval(date('n',$t));
  $lastDay = intval(date('t', strtotime("$y-$m-01")));
  return sprintf("%04d-%02d-%02d", $y, $m, $lastDay);
}
function mm_union_months(array $maps): array {
  $set = [];
  foreach ($maps as $map) foreach ($map as $d => $_) $set[mm_month_end($d)] = true;
  $dates = array_keys($set); sort($dates); return $dates;
}
function mm_resample_monthly(array $map, array $baseMonths): array {
  $src = array_keys($map); sort($src);
  $out = []; $last = null; $i = 0; $n = count($src);
  foreach ($baseMonths as $mEnd) {
    while ($i < $n && $src[$i] <= $mEnd) { $last = $map[$src[$i]]; $i++; }
    $out[$mEnd] = ($last !== null ? $last : null);
  }
  return $out;
}
function mm_pct_change(array $m, int $N): array {
  $keys = array_keys($m); $n = count($keys); $out = array_fill_keys($keys, null);
  for ($i=0; $i<$n; $i++) if ($i >= $N) {
    $cur = $m[$keys[$i]]; $prev = $m[$keys[$i-$N]];
    if ($cur !== null && $prev !== null && $prev != 0.0) $out[$keys[$i]] = 100.0 * ($cur/$prev - 1.0);
  }
  return $out;
}
function mm_zscore(array $m, int $N): array {
  $keys = array_keys($m); $vals = array_values($m);
  $n=count($keys); $out = array_fill_keys($keys, null);
  for ($i=0; $i<$n; $i++) if ($i+1 >= $N) {
    $win = array_slice($vals, $i-$N+1, $N);
    $ok  = array_filter($win, fn($v)=>$v!==null && is_finite($v));
    if (count($ok)==$N) {
      $mu = array_sum($ok)/$N; $var=0.0; foreach($ok as $v){ $var += ($v-$mu)*($v-$mu); } $var /= $N;
      $sd = $var>0.0 ? sqrt($var) : null;
      $out[$keys[$i]] = ($sd && $sd>0.0) ? ($vals[$i]-$mu)/$sd : 0.0;
    }
  }
  return $out;
}
function mm_apply_ops(array $m, string $opsStr): array {
  if ($opsStr==='') return $m;
  $ops = array_filter(array_map('trim', explode(',', $opsStr)));
  $cur = $m;
  $logs = in_array('log',$ops,true) ? ['log'] : [];
  $invs = in_array('inv',$ops,true) ? ['inv'] : [];
  $zs   = array_values(array_filter($ops, fn($x)=>str_starts_with($x,'z')));
  $pcts = array_values(array_filter($ops, fn($x)=>str_starts_with($x,'pct')));
  $ordered = array_merge($logs, $invs, $zs, $pcts);

  foreach ($ordered as $op) {
    if ($op==='log') {
      foreach ($cur as $d=>$v) $cur[$d] = ($v!==null && $v>0.0) ? log($v) : null;
    } elseif ($op==='inv') {
      foreach ($cur as $d=>$v) $cur[$d] = ($v!==null && $v!=0.0) ? 1.0/$v : null;
    } elseif (preg_match('/^z(\d+)?$/',$op,$m1)) {
      $win = isset($m1[1]) ? max(3,intval($m1[1])) : 36;
      $cur = mm_zscore($cur, $win);
    } elseif (preg_match('/^pct(3|6|12)$/',$op,$m2)) {
      $N = intval($m2[1]);
      $cur = mm_pct_change($cur, $N);
    }
  }
  return $cur;
}
function mm_crop_clean(array $m, ?string $FROM, ?string $TO): array {
  $out = [];
  foreach ($m as $d=>$v) {
    if ($FROM && $d<$FROM) continue;
    if ($TO   && $d>$TO)   continue;
    $out[$d] = $v;
  }
  $keys = array_keys($out); $vals=array_values($out); $n=count($keys);
  $s=0; while ($s<$n && $vals[$s]===null) $s++;
  $e=$n-1; while ($e>=0 && $vals[$e]===null) $e--;
  if ($s>$e) return [];
  $clean=[];
  for ($i=$s;$i<=$e;$i++) $clean[$keys[$i]]=$vals[$i];
  return $clean;
}

// ==================== FETCH FX (RapidAPI Yahoo, 1mo) ====================
try {
  $headers = [
    "x-rapidapi-host: $RAPID_HOST",
    "x-rapidapi-key: $RAPID_KEY",
  ];

  $fx_raw = [];
  foreach ($FX_SYMBOLS as $k => $sym) {
    $url = "https://$RAPID_HOST/api/v1/markets/stock/history?symbol=$sym&interval=$FX_INTERVAL&diffandsplits=false";
    $fx_raw[$k] = curl_json($url, $headers);
    if (!isset($fx_raw[$k]['body']) || !is_array($fx_raw[$k]['body'])) {
      throw new RuntimeException("No body for $k");
    }
  }

  $usd_jpy = []; // date => close
  $gbp_usd = [];
  $eur_usd = [];

  foreach ($fx_raw['USDJPY']['body'] as $node) {
    $d = substr($node['date'],0,10);
    $usd_jpy[$d] = floatval($node['close']);
  }
  foreach ($fx_raw['GBPUSD']['body'] as $node) {
    $d = substr($node['date'],0,10);
    $gbp_usd[$d] = floatval($node['close']);
  }
  foreach ($fx_raw['EURUSD']['body'] as $node) {
    $d = substr($node['date'],0,10);
    $eur_usd[$d] = floatval($node['close']);
  }

  // Derive USD/GBP and USD/EUR by inversion
  $usd_gbp = []; // USD/GBP = 1 / (GBP/USD)
  $usd_eur = []; // USD/EUR = 1 / (EUR/USD)
  foreach ($gbp_usd as $d => $v) { if ($v != 0.0) $usd_gbp[$d] = 1.0 / $v; }
  foreach ($eur_usd as $d => $v) { if ($v != 0.0) $usd_eur[$d] = 1.0 / $v; }

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>FX fetch error: ".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

// ==================== DB: CRUDE & VIX ====================
try {
  $mysqli = mysql_conn($DB);
  $crude = load_table_kv($mysqli, $DB['tbl_crude']); // date=>value
  $vix   = load_table_kv($mysqli, $DB['tbl_vix']);   // date=>value
  $mysqli->close();
} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>DB error: ".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

// ==================== BUILD COMPOSITE (native monthly) ====================
$dates = array_keys($usd_jpy);
sort($dates);

$out_dates = [];
$out_index = [];

foreach ($dates as $d) {
  if (!within_range($d, $FROM, $TO)) continue;

  $usdjpy = $usd_jpy[$d] ?? null;
  $usdgbp = $usd_gbp[$d] ?? null;
  $usdeur = $usd_eur[$d] ?? null;

  $cru   = value_on_or_before($crude, $d);
  $vixv  = value_on_or_before($vix, $d);

  if ($usdjpy === null || $usdgbp === null || $usdeur === null || $cru === null || $vixv === null) continue;

  // USDJPY term
  if ($USE_INVERSE_USDJPY) {
    if ($usdjpy == 0.0) continue;
    $usdjpy_term = 1.0 / $usdjpy;
  } else {
    $usdjpy_term = $usdjpy - 1.0;
  }

  // Numerator: (USDJPY term) * (Crude*2)
  $num = $usdjpy_term * ($cru * $K_CRUDE);

  // Denominator
  $den = $usdgbp * ($usdeur * $K_USDEUR) * ($vixv * $K_VIX) * $K_DEN;
  if ($den == 0.0) continue;

  $idx = $num / $den;

  $out_dates[] = $d;
  $out_index[] = $idx;
}

/* ======== Overlay input (GET add[]/ops[]/label[]) + labels for DB ======== */
$MM_ASSETS = [
  'Bitcoin-USD'             => 'Bitcoin USD',
  'eth_usd'                 => 'ETH USD',
  'ethbtc_usd_weekly'       => 'ETH/BTC',
  'nasdaq_usd'              => 'NASDAQ',
  'SP500'                   => 'S&P 500',
  'nikkei_225_usd_daily'    => 'Nikkei 225 (USD)',
  'russell_2000_usd'        => 'Russell 2000',
  'Copper-to-gold-ratio'    => 'Copper/Gold',
  'gold_usd'                => 'Gold',
  'silver_usd'              => 'Silver',
];

$MM_add   = isset($_GET['add'])   ? (array)$_GET['add']   : [];
$MM_ops   = isset($_GET['ops'])   ? (array)$_GET['ops']   : [];
$MM_label = isset($_GET['label']) ? (array)$_GET['label'] : [];
$MM_YF_INTERVAL = '1mo';

/* ======== Gather raw overlay maps (native dates) ======== */
$raw_maps  = [];
$raw_names = [];

if (!empty($MM_add)) {
  try { $__mm = mysql_conn($DB); } catch (Throwable $e) { $__mm = null; }

  foreach ($MM_add as $i=>$src) {
    $lab = trim($MM_label[$i] ?? '');
    if (strncasecmp($src,'YAHOO:',6)===0) {
      $sym = substr($src,6);
      try {
        $j = curl_json(
          "https://$RAPID_HOST/api/v1/markets/stock/history?symbol=".urlencode($sym)."&interval=$MM_YF_INTERVAL&diffandsplits=false",
          ["x-rapidapi-host: $RAPID_HOST","x-rapidapi-key: $RAPID_KEY"]
        );
        if (!isset($j['body']) || !is_array($j['body'])) throw new RuntimeException("No body for $sym");
        $m = [];
        foreach ($j['body'] as $node) {
          $d = substr($node['date'],0,10);
          $v = floatval($node['close'] ?? $node['adjclose'] ?? NAN);
          if (is_finite($v)) $m[$d] = $v;
        }
        $raw_maps[]  = $m;
        $raw_names[] = $lab!=='' ? $lab : "Yahoo $sym";
      } catch (Throwable $e) {
        echo "<pre>RapidAPI error ($sym): ".htmlspecialchars($e->getMessage())."</pre>";
        $raw_maps[]  = [];
        $raw_names[] = $lab!=='' ? $lab : "Yahoo $sym";
      }
    } else {
      try {
        if ($src!=='' && !array_key_exists($src, $MM_ASSETS)) throw new RuntimeException("Unknown series $src");
        $m = ($src!=='' && $__mm) ? mm_load_table($__mm, $src) : [];
        $raw_maps[]  = $m;
        $raw_names[] = ($src!=='' ? ($lab!=='' ? $lab : ($MM_ASSETS[$src] ?? $src)) : ($lab!=='' ? $lab : ''));
      } catch (Throwable $e) {
        echo "<pre>Load error ($src): ".htmlspecialchars($e->getMessage())."</pre>";
        $raw_maps[]  = [];
        $raw_names[] = $lab!=='' ? $lab : $src;
      }
    }
  }
  if ($__mm) $__mm->close();
}

/* ======== Build MASTER month-end axis from composite + overlays ======== */
// Composite month-end keyed map
$comp_map_native = [];
for ($i=0; $i<count($out_dates); $i++) {
  $comp_map_native[ mm_month_end($out_dates[$i]) ] = $out_index[$i]; // last in month wins
}
$master_set = [];
foreach (array_keys($comp_map_native) as $d) $master_set[$d] = true;
foreach ($raw_maps as $rm) foreach ($rm as $d => $_) $master_set[ mm_month_end($d) ] = true;
$MASTER_MONTHS = array_keys($master_set);
sort($MASTER_MONTHS);

/* ======== Resample composite to MASTER, crop, and compute gauge ======== */
$comp_rs = mm_resample_monthly($comp_map_native, $MASTER_MONTHS);
$comp_rs = mm_crop_clean($comp_rs, $FROM, $TO);
$out_dates = array_keys($comp_rs);
$out_index = array_values($comp_rs);

// Gauge arrays recompute on the resampled composite
$g_dates = $out_dates;
$g_vals  = $out_index;
$N = count($g_vals);

$g_out   = array_fill(0, $N, null); // 0..100
$logp    = [];
for ($i=0; $i<$N; $i++) $logp[$i] = log(max(1e-12, $g_vals[$i]));

// momentum raw (log ratio over momLen)
$momRaw = array_fill(0, $N, null);
for ($i=0; $i<$N; $i++) {
  $j = $i - $momLen;
  if ($j >= 0) $momRaw[$i] = $logp[$i] - $logp[$j];
}
$momRawFilled = array_map(function($v){ return $v===null ? 0.0 : $v; }, $momRaw);
$momSmSeries = ema_series($momRawFilled, $momSmooth);

// decay series since 2009-01-03
$genesis = strtotime('2009-01-03');
$decay = array_fill(0, $N, 1.0);
if ($drEnabled) {
  for ($i=0; $i<$N; $i++) {
    $yrs = (strtotime($g_dates[$i]) - $genesis) / (365.25*24*3600);
    if ($drModeExp) $decay[$i] = exp(-$yrs / $drHalfYrs);
    else $decay[$i] = 1.0 / (1.0 + $drBeta * $yrs);
    if ($decay[$i] < 1e-9) $decay[$i] = 1e-9;
  }
}

// base: log(c / SMA(len))
$tgoBase = array_fill(0, $N, null);
for ($i=0; $i<$N; $i++) {
  if ($i >= $len-1) {
    $sma = sma_at($g_vals, $i, $len);
    $tgoBase[$i] = log(max(1e-12, $g_vals[$i] / max(1e-12, $sma)));
  }
}

// combined
$tgoCombined = array_fill(0, $N, null);
for ($i=0; $i<$N; $i++) {
  if ($tgoBase[$i] === null) continue;
  $b = $drBase ? ($tgoBase[$i] * $decay[$i]) : $tgoBase[$i];
  $tgoCombined[$i] = $b + $momWeight * $decay[$i] * $momSmSeries[$i];
}

// TGO percent ranks
$tgoPct     = array_fill(0,  $N, null);
$tgoPctWarm = array_fill(0,  $N, null);
for ($i=0; $i<$N; $i++) {
  if ($tgoCombined[$i] !== null) {
    $tgoPct[$i]     = percent_rank_at($tgoCombined, $i, $normWin);
    $tgoPctWarm[$i] = percent_rank_at($tgoCombined, $i, $warmWin);
  }
}

// BDO core
$bdo0 = array_fill(0, $N, null);
$posLen = max(10, intdiv($bdoWin, max(1,$posLenFrac)));
$posCapSeries = array_fill(0, $N, null);

for ($i=0; $i<$N; $i++) {
  $baseline = linreg_current($logp, $i, $bdoWin);
  if ($baseline === null) continue;

  if ($mode === 'BDO_EMA' || $mode === 'BDO_PCTL') {
    $start = $i - $bdoWin + 1;
    if ($start < 0) continue;
    $resWin = [];
    for ($k=$start; $k<=$i; $k++) $resWin[] = max(0.0, $logp[$k] - linreg_current($logp, $k, min($bdoWin, $k+1)));
    if (count($resWin) > 0) {
      $emaWin = ema_series($resWin, $posLen);
      $posCapSeries[$i] = end($emaWin);
    }
  }

  // sliding quantiles
  $lowQ = percentile_at(array_map(function($k) use ($logp,$bdoWin){
              return $logp[$k] - linreg_current($logp, $k, min($bdoWin, $k+1));
            }, range(max(0,$i-$bdoWin+1), $i)), count($logp)-1, min($bdoWin, $i+1), $bdoQLow);
  $highQ = percentile_at(array_map(function($k) use ($logp,$bdoWin){
              return $logp[$k] - linreg_current($logp, $k, min($bdoWin, $k+1));
            }, range(max(0,$i-$bdoWin+1), $i)), count($logp)-1, min($bdoWin, $i+1), $bdoQHigh);

  $upper = null;
  if ($mode === 'BDO_EMA' && $posCapSeries[$i] !== null) $upper = $baseline + $posCapSeries[$i];
  if ($mode === 'BDO_PCTL' && $highQ !== null)           $upper = $baseline + $highQ;
  $lower = ($lowQ !== null) ? $baseline + $lowQ : null;

  if ($upper !== null && $lower !== null && $upper != $lower) {
    $val = max(0.0, min(1.0, ($logp[$i] - $lower)/($upper - $lower)));
    $bdo0[$i] = 100.0 * $val;
  }
}

// Readiness & final out
for ($i=0; $i<$N; $i++) {
  $readyTGO = ($tgoPct[$i] !== null);
  $out = null;

  if ($mode === 'TGO_PERCRANK') {
    $out = $readyTGO ? $tgoPct[$i] : $tgoPctWarm[$i];
  } else {
    $readyBDO = ($bdo0[$i] !== null) && ($mode === 'BDO_EMA' ? ($posCapSeries[$i] !== null) : true);
    if ($readyBDO) {
      if ($bdoOn && $bdoBlendW > 0.0 && $tgoPct[$i] !== null) {
        $out = (1.0 - $bdoBlendW) * $tgoPct[$i] + $bdoBlendW * $bdo0[$i];
      } else {
        $out = $bdo0[$i];
      }
    } else {
      $out = $readyTGO ? $tgoPct[$i] : $tgoPctWarm[$i];
    }
  }
  $g_out[$i] = $out;
}

// Latest gauge value
$latestGauge = null;
for ($i=$N-1; $i>=0; $i--) { if ($g_out[$i] !== null) { $latestGauge = $g_out[$i]; break; } }

/* ======== Build overlay traces on MASTER axis (resample + transforms + crop) ======== */
$MM_traces = [];
$MM_axes   = [];

$left = false; $leftCount=0; $rightCount=0;
for ($i=0; $i<count($raw_maps); $i++) {
  // resample to MASTER, then ops, then crop
  $m = mm_resample_monthly($raw_maps[$i], $MASTER_MONTHS);
  $m = mm_apply_ops($m, $MM_ops[$i] ?? '');
  $m = mm_crop_clean($m, $FROM, $TO);
  if (empty($m)) continue;

  $dates2 = array_keys($m);
  $vals2  = array_values($m);
  $yref   = 'y'.($i+2); // main composite is y
  $yaxis_layout_name = 'yaxis'.($i+2); // main composite is y

  $MM_traces[] = [
    'x' => $dates2,
    'y' => $vals2,
    'type' => 'scatter',
    'mode' => 'lines',
    'name' => $raw_names[$i].( ($MM_ops[$i]??'') ? " [".$MM_ops[$i]."]" : "" ),
    'yaxis' => $yref,
    'connectgaps' => true
  ];

  $side = $left ? 'left' : 'right';
  if ($side==='left') $leftCount++; else $rightCount++;
  
  if($i > 0){
  	$pos = ($side==='left') ? min(0.08*$leftCount, 0.45) : max(1.0 - 0.08*$rightCount, 0.55);
  } else {
  	$pos = 1;
  }

  $MM_axes[$yaxis_layout_name] = [
    'title' => $raw_names[$i],
    'gridcolor' => '#18202b',
    'zerolinecolor' => '#233043',
    'side' => $side,
    'overlaying' => 'y',
    'position' => $pos
  ];
  $left = !$left;
}

// ==================== HTML + PLOTLY ====================
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8" />
  <title>Composite Index (Monthly) + Gauge</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet" />
  <style>
    body { background:#0b0f14; color:#e5e9f0; font-family: Nunito, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans"; margin:0; padding:10px; font-size: 12px;}
    h2, h3{margin-top: 0; margin-bottom: 0px;}
    .card {background:#11161d; border:1px solid #1f2937; border-radius:12px; padding:16px; box-shadow: 0 4px 20px rgba(0,0,0,0.35);}
    .controls { margin-bottom: 12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    input[type="date"], input[type="text"], input[type="number"] { background:#0b0f14; color:#e5e9f0; border:1px solid #1f2937; border-radius:8px; padding:6px 10px; }
    label { margin-right:8px; }
    button { background:#1f2937; color:#e5e9f0; border:1px solid #334155; padding:6px 12px; border-radius:8px; cursor:pointer;}
    button:hover { background:#2a3648; }
    .muted { color:#9aa0a6; font-size: 0.9rem; }
    .grid { display:grid; grid-template-columns: 1fr 300px; gap:16px; align-items:start; }
    @media (max-width: 1000px) { .grid { grid-template-columns: 1fr; } }
    .rowcard { background:#0b0f14; border:1px solid #1f2937; border-radius:10px; padding:10px; }
    .tag { border:1px solid #1f2937;padding:2px 6px;border-radius:6px; display:inline-block; margin-right:6px;}
  </style>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
</head>
<body>
  <div class="card">
    <div class="grid">
      <div>
        <h2>Composite Index (Monthly)</h2>
        <div class="muted">Formula: ((USDJPY_term × (Crude×2)) / (USD/GBP × (USD/EUR×0.576) × (VIX×1e-6) × 24,000,000))</div>
      </div>
      <div id="gauge" style="width:100%;height:60px;"></div>
    </div>

    <div class="controls">
      <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <label>From <input type="date" name="from" value="<?= htmlspecialchars($FROM ?? '') ?>"></label>
        <label>To <input type="date" name="to" value="<?= htmlspecialchars($TO ?? '') ?>"></label>
        <label><input type="checkbox" name="inverse_jpy" value="1" <?= $USE_INVERSE_USDJPY ? 'checked' : '' ?>> Inverse USDJPY</label>

        <label>Mode
          <select name="mode" style="background:#0b0f14;color:#e5e9f0;border:1px solid #1f2937;border-radius:8px;padding:6px 10px;">
            <?php foreach (['TGO_PERCRANK','BDO_PCTL','BDO_EMA'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $mode===$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>len <input type="number" name="len" value="<?= htmlspecialchars($len) ?>" step="1" min="1" style="width:70px;"></label>
        <label>momLen <input type="number" name="momLen" value="<?= htmlspecialchars($momLen) ?>" step="1" min="1" style="width:70px;"></label>
        <label>momSmooth <input type="number" name="momSmooth" value="<?= htmlspecialchars($momSmooth) ?>" step="1" min="1" style="width:70px;"></label>
        <label>momWeight <input type="number" name="momWeight" value="<?= htmlspecialchars($momWeight) ?>" step="0.05" style="width:80px;"></label>

        <label>drOn <input type="checkbox" name="drEnabled" value="1" <?= $drEnabled? 'checked':'' ?>></label>
        <label>exp? <input type="checkbox" name="drModeExp" value="1" <?= $drModeExp? 'checked':'' ?>></label>
        <label>halfYrs <input type="number" name="drHalfYrs" value="<?= htmlspecialchars($drHalfYrs) ?>" step="0.1" style="width:90px;"></label>
        <label>beta <input type="number" name="drBeta" value="<?= htmlspecialchars($drBeta) ?>" step="0.01" style="width:80px;"></label>
        <label>decay base? <input type="checkbox" name="drBase" value="1" <?= $drBase? 'checked':'' ?>></label>

        <label>bdoOn <input type="checkbox" name="bdoOn" value="1" <?= $bdoOn? 'checked':'' ?>></label>
        <label>qLow <input type="number" name="bdoQLow" value="<?= htmlspecialchars($bdoQLow) ?>" step="0.5" style="width:80px;"></label>
        <label>qHigh <input type="number" name="bdoQHigh" value="<?= htmlspecialchars($bdoQHigh) ?>" step="0.5" style="width:80px;"></label>
        <label>bdoW <input type="number" name="bdoBlendW" value="<?= htmlspecialchars($bdoBlendW) ?>" step="0.05" style="width:80px;"></label>
        <label>bdoWin <input type="number" name="bdoWin" value="<?= htmlspecialchars($bdoWin) ?>" step="1" style="width:90px;"></label>
        <label>posLenFrac <input type="number" name="posLenFrac" value="<?= htmlspecialchars($posLenFrac) ?>" step="1" style="width:110px;"></label>
        <label>normWin <input type="number" name="normWin" value="<?= htmlspecialchars($normWin) ?>" step="1" style="width:90px;"></label>
        <label>warmWin <input type="number" name="warmWin" value="<?= htmlspecialchars($warmWin) ?>" step="1" style="width:90px;"></label>

        <button type="submit">Apply</button>
      </form>
    </div>

    <!-- ===== Overlay Assets (simple dropdown / symbol + checkboxes) ===== -->
    <div class="card" style="background:#0e1420; margin:8px 0 16px 0;">
      <h3>Assets Overlay → plotted on the MAIN chart</h3>
      <p class="muted">
        Pick a DB table or enter a Yahoo symbol (via RapidAPI). Tick any transforms. You can add multiple rows; each gets its own y-axis.<br>
        <span class="tag">log</span><span class="tag">inverse</span><span class="tag">zN</span><span class="tag">pct3</span><span class="tag">pct6</span><span class="tag">pct12</span>
      </p>

      <form id="mm_cfg" method="GET" class="controls" onsubmit="return mm_buildAndSubmitSimple();">
        <input type="hidden" name="from" value="<?= htmlspecialchars($FROM ?? '') ?>">
        <input type="hidden" name="to"   value="<?= htmlspecialchars($TO ?? '') ?>">

        <!-- Server-rendered rows from GET; at least one row -->
        <div id="mm_rows">
        <?php
          $seedN = max(1, count($MM_add));
          for ($r=0; $r<$seedN; $r++):
            $src  = $MM_add[$r]   ?? '';
            $opsS = $MM_ops[$r]   ?? '';
            $lbl  = $MM_label[$r] ?? '';
            $isY  = strncasecmp($src,'YAHOO:',6)===0;
            $sym  = $isY ? substr($src,6) : '';
            $isDB = (!$isY && $src!=='' && isset($MM_ASSETS[$src]));
            $has  = function($k) use($opsS){ return (strpos($opsS,$k)!==false); };
            $zwin = 36; if (preg_match('/z(\d+)/',$opsS,$m)) $zwin=max(3,intval($m[1]));
        ?>
          <div class="rowcard" data-idx="<?= $r ?>" style="display:grid;grid-template-columns:260px 220px 1fr 200px 60px;gap:10px;align-items:end;">
            <div>
              <div><b>Source</b></div>
              <select class="mm_src" style="width:100%;" onchange="mm_toggleYahooSimple(this);">
                <option value="" <?= ($src===''?'selected':'') ?> disabled>Choose…</option>
                <optgroup label="DB tables">
                <?php foreach ($MM_ASSETS as $tbl=>$label): ?>
                  <option value="<?= htmlspecialchars($tbl) ?>" <?= ($isDB && $src===$tbl?'selected':'') ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
                </optgroup>
                <optgroup label="Yahoo via RapidAPI">
                  <option value="YAHOO" <?= ($isY?'selected':'') ?>>Type symbol below…</option>
                </optgroup>
              </select>
            </div>
            <div>
              <div><b>Yahoo symbol</b></div>
              <input type="text" class="mm_sym" placeholder="e.g., BTC-USD, ^GSPC" value="<?= htmlspecialchars($sym) ?>" style="width:100%; <?= $isY?'':'display:none;' ?>">
            </div>
            <div>
              <div><b>Transforms (tick any)</b></div>
              <label><input type="checkbox" class="mm_tf_log" <?= $has('log')?'checked':'' ?>> log</label>
              <label><input type="checkbox" class="mm_tf_inv" <?= $has('inv')?'checked':'' ?>> inverse</label>
              <label><input type="checkbox" class="mm_tf_z"   <?= $has('z')  ?'checked':'' ?> onchange="mm_zEn(this);"> z</label>
              win <input type="number" class="mm_zwin" value="<?= $zwin ?>" min="3" step="1" style="width:70px;" <?= $has('z')?'':'disabled' ?>>
              <label style="margin-left:10px;"><input type="checkbox" class="mm_tf_pct3"  <?= $has('pct3')?'checked':'' ?>> pct3</label>
              <label><input type="checkbox" class="mm_tf_pct6"  <?= $has('pct6')?'checked':'' ?>> pct6</label>
              <label><input type="checkbox" class="mm_tf_pct12" <?= $has('pct12')?'checked':'' ?>> pct12</label>
            </div>
            <div>
              <div><b>Legend label</b></div>
              <input type="text" class="mm_lbl" placeholder="Optional" value="<?= htmlspecialchars($lbl) ?>" style="width:100%;">
            </div>
            <div style="text-align:right;">
              <button type="button" onclick="this.closest('.rowcard').remove(); mm_buildAndSubmitSimple();">✕</button>
            </div>
          </div>
        <?php endfor; ?>
        </div>

        <button type="button" onclick="mm_addRowSimple()">Add Asset</button>
        <button type="submit">Overlay on Main</button>
      </form>
    </div>

    <div id="chart" style="width:100%;height:500px;"></div>
  </div>

  <div class="card" style="margin-top:16px;">
    <h3>TGO/BDO Gauge (0-100) — History</h3>
    <div id="gaugeHistory" style="width:100%;height:35vh;"></div>
    <p class="muted">Bands at 25/50/75. Latest value also shown in the top gauge.</p>
  </div>

<script>
const dates = <?= json_encode($out_dates, JSON_UNESCAPED_SLASHES) ?>;
const idx   = <?= json_encode($out_index, JSON_UNESCAPED_SLASHES) ?>;

// MAIN CHART traces: composite + overlays
const compositeTrace = { x: dates, y: idx, type: 'scatter', mode: 'lines', name: 'Composite Index', connectgaps:true };

// Overlay traces from PHP
const MM_traces = <?= json_encode($MM_traces, JSON_UNESCAPED_SLASHES) ?>;
const MM_axes   = <?= json_encode($MM_axes,   JSON_UNESCAPED_SLASHES) ?>;

// Base layout
const layout = {
  paper_bgcolor: '#0b0f14',
  plot_bgcolor : '#0b0f14',
  font: { color:'#e5e9f0' },
  xaxis: { title: 'Date', type:'date', gridcolor: '#18202b', zerolinecolor: '#233043' },
  yaxis: { title: 'Composite', gridcolor: '#18202b', zerolinecolor: '#233043' },
  margin: {l:80,r:100,t:10,b:50},
  hovermode: 'x unified',
  legend: { orientation:'h', y:-0.2 }
};
// Merge in overlay axes (y2, y3, …)
for (const [k,v] of Object.entries(MM_axes)) layout[k] = v;

// Render main
Plotly.newPlot('chart', [compositeTrace, ...(MM_traces||[])], layout, {displaylogo:false, responsive:true});

// Gauge history
const gDates = <?= json_encode($g_dates, JSON_UNESCAPED_SLASHES) ?>;
const gOut   = <?= json_encode($g_out, JSON_UNESCAPED_SLASHES) ?>;

const gaugeLine = { x: gDates, y: gOut, type:'scatter', mode:'lines', name:'Gauge (0-100)' };
const hline = (y)=>({ x:[gDates[0], gDates[gDates.length-1]], y:[y,y], type:'scatter', mode:'lines', name:`${y}`,
                      line:{ dash:'dot' }, hoverinfo:'skip', showlegend:false });

const layoutGaugeHist = {
  paper_bgcolor:'#0b0f14', plot_bgcolor:'#0b0f14', font:{color:'#e5e9f0'},
  xaxis:{ gridcolor:'#18202b', zerolinecolor:'#233043' },
  yaxis:{ range:[0,100], gridcolor:'#18202b', zerolinecolor:'#233043', title:'0-100' },
  margin:{l:60,r:20,t:10,b:40}, hovermode:'x unified'
};
const extras = [];
if (gDates.length>1) { extras.push(hline(25), hline(50), hline(75)); }
Plotly.newPlot('gaugeHistory', [gaugeLine, ...extras], layoutGaugeHist, {displaylogo:false, responsive:true});

/* ===== Simple overlay UI (dropdown/symbol + checkboxes) ===== */
const MM_DB_SERIES = <?= json_encode($MM_ASSETS, JSON_UNESCAPED_SLASHES) ?>;

function mm_rowSimpleTpl(idx){
  const dbOpts = Object.entries(MM_DB_SERIES).map(([tbl,label])=>`<option value="${tbl}">${label}</option>`).join('');
  return `
  <div class="rowcard" data-idx="${idx}" style="display:grid;grid-template-columns:260px 220px 1fr 200px 60px;gap:10px;align-items:end;">
    <div>
      <div><b>Source</b></div>
      <select class="mm_src" onchange="mm_toggleYahooSimple(this);" style="width:100%;">
        <option value="" selected disabled>Choose…</option>
        <optgroup label="DB tables">${dbOpts}</optgroup>
        <optgroup label="Yahoo via RapidAPI"><option value="YAHOO">Type symbol below…</option></optgroup>
      </select>
    </div>
    <div>
      <div><b>Yahoo symbol</b></div>
      <input type="text" class="mm_sym" placeholder="e.g., BTC-USD, ^GSPC" style="width:100%; display:none;">
    </div>
    <div>
      <div><b>Transforms (tick any)</b></div>
      <label><input type="checkbox" class="mm_tf_log"> log</label>
      <label><input type="checkbox" class="mm_tf_inv"> inverse</label>
      <label><input type="checkbox" class="mm_tf_z" onchange="mm_zEn(this);"> z</label>
      win <input type="number" class="mm_zwin" value="36" min="3" step="1" style="width:70px;" disabled>
      <label style="margin-left:10px;"><input type="checkbox" class="mm_tf_pct3"> pct3</label>
      <label><input type="checkbox" class="mm_tf_pct6"> pct6</label>
      <label><input type="checkbox" class="mm_tf_pct12" checked> pct12</label>
    </div>
    <div>
      <div><b>Legend label</b></div>
      <input type="text" class="mm_lbl" placeholder="Optional" style="width:100%;">
    </div>
    <div style="text-align:right;">
      <button type="button" onclick="this.closest('.rowcard').remove(); mm_buildAndSubmitSimple();">✕</button>
    </div>
  </div>`;
}
function mm_toggleYahooSimple(sel){
  const sym = sel.closest('.rowcard').querySelector('.mm_sym');
  sym.style.display = (sel.value==='YAHOO') ? 'block' : 'none';
}
function mm_zEn(cb){
  const win = cb.closest('.rowcard').querySelector('.mm_zwin');
  win.disabled = !cb.checked;
}
let mm_idx = document.querySelectorAll('#mm_rows .rowcard').length || 0;
function mm_addRowSimple(){ document.getElementById('mm_rows').insertAdjacentHTML('beforeend', mm_rowSimpleTpl(mm_idx++)); }

function mm_buildAndSubmitSimple(){
  const form = document.getElementById('mm_cfg');
  // purge old hidden fields if any
  Array.from(form.querySelectorAll('input[name="add[]"],input[name="ops[]"],input[name="label[]"]')).forEach(n=>n.remove());

  const rows = document.querySelectorAll('#mm_rows .rowcard');
  if (rows.length===0){ alert('Add at least one asset'); return false; }

  rows.forEach(row=>{
    const srcSel = row.querySelector('.mm_src')?.value || '';
    if (!srcSel) return;
    let addVal = srcSel;
    if (srcSel==='YAHOO') {
      const sym = (row.querySelector('.mm_sym')?.value || '').trim();
      if (!sym) return;
      addVal = 'YAHOO:'+sym;
    }
    const lbl = (row.querySelector('.mm_lbl')?.value || '').trim();

    // Build ops string in fixed order
    const ops = [];
    if (row.querySelector('.mm_tf_log')?.checked) ops.push('log');
    if (row.querySelector('.mm_tf_inv')?.checked) ops.push('inv');
    const zOn = row.querySelector('.mm_tf_z')?.checked;
    if (zOn) {
      const zwin = Math.max(3, parseInt(row.querySelector('.mm_zwin')?.value || '36',10));
      ops.push('z'+zwin);
    }
    if (row.querySelector('.mm_tf_pct3')?.checked)  ops.push('pct3');
    if (row.querySelector('.mm_tf_pct6')?.checked)  ops.push('pct6');
    if (row.querySelector('.mm_tf_pct12')?.checked) ops.push('pct12');

    form.insertAdjacentHTML('beforeend', `<input type="hidden" name="add[]" value="${mm_html(addVal)}">`);
    form.insertAdjacentHTML('beforeend', `<input type="hidden" name="ops[]" value="${mm_html(ops.join(','))}">`);
    form.insertAdjacentHTML('beforeend', `<input type="hidden" name="label[]" value="${mm_html(lbl)}">`);
  });

  // submit GET back to same page
  form.submit();
  return false; // prevent default, we manually submitted
}
function mm_html(s){ return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Small top gauge (latest)
const latestVal = <?= $latestGauge === null ? 'null' : json_encode($latestGauge) ?>;
const gaugeData = [{
  type: "indicator",
  mode: "gauge+number",
  value: latestVal ?? 0,
  title: { text: "Gauge (latest)", font:{size:14} },
  gauge: {
    axis: { range: [0, 100] },
    bar: { thickness: 0.4 },
    steps: [ {range:[0,25]}, {range:[25,50]}, {range:[50,75]}, {range:[75,100]} ]
  },
  domain: { x: [0, 1], y: [0, 1] }
}];
const gaugeLayout = {
  paper_bgcolor:'#11161d', plot_bgcolor:'#11161d',
  font:{color:'#e5e9f0'}, margin:{l:10,r:10,t:10,b:10}, height:50
};
Plotly.newPlot('gauge', gaugeData, gaugeLayout, {displaylogo:false, responsive:true});
</script>
</body>
</html>

