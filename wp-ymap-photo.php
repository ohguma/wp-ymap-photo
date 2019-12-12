<?php
/*
Plugin Name: WP Yahoo! Map Photo Album
Description: Photos to be displayed on the Yahoo! map with GPS information for each post.
Author: ohguma
Version: 1.0
*/

function add_wpymapphoto_menu()
{
    add_options_page('Yahoo Map Photo Album', 'Yahoo!地図 Photo Album設定', 'manage_options', 'wp-ymap-photo.php', 'wpymapphoto_page');
}
add_action('admin_menu', 'add_wpymapphoto_menu');

function wpymapphoto_page()
{
    ?>
    <div class="wrap">
        <?php screen_icon(); ?>
        <h2>Yahoo!地図 Photo Album設定</h2>
        <?php
        if (isset($_POST['wpymapphoto_appid'])) {
            check_admin_referer('wpymapphoto_action', 'wpymapphoto_nonce');
            $wpymapphoto_appid = stripslashes($_POST['wpymapphoto_appid']);
            update_option('wpymapphoto_appid', $wpymapphoto_appid);
            echo '<p>アプリケーションIDを保存しました。</p>';
        } else {
            $wpymapphoto_appid = get_option('wpymapphoto_appid');
            echo '<p>アプリケーションIDを入力します。</p>';
        }
        ?>
        <form action="" method="post">
            <?php wp_nonce_field('wpymapphoto_action', 'wpymapphoto_nonce'); ?>
            <input type="text" name="wpymapphoto_appid" value="<?php echo esc_attr($wpymapphoto_appid); ?>" size="100" />
            <?php submit_button(); ?>
        </form>
        <h3>利用方法</h3>
        <p>こちらの<a href="https://developer.yahoo.co.jp/start/" target="_blank">「ご利用ガイド」</a>の手順に従い、アプリケーションを登録して、アプリケーションIDを取得します。</p>
        <ol>
            <li>Yahoo! JAPAN IDを取得</li>
            <li>アプリケーションを登録</li>
            <ul>
                <li style="list-style-type: disc; margin-left:2em">アプリケーションの種類：サーバサイド</li>
                <li style="list-style-type: disc; margin-left:2em">連絡先メールアドレス：（受信できるもの）</li>
                <li style="list-style-type: disc; margin-left:2em">アプリケーション名：（サイト名称など。管理用の名称）</li>
                <li style="list-style-type: disc; margin-left:2em">サイトURL：（本プラグインを使うサイトのトップページURL）</li>
            </ul>
        </ol>
    </div>
    <?php
}

class WpYmapPhoto
{
    /**
     * デベロッパーネットワークトップ > YOLP(地図)
     * https://developer.yahoo.co.jp/webapi/map/
     *
     * Yahoo! JavaScriptマップAPI
     * https://developer.yahoo.co.jp/webapi/map/openlocalplatform/v1/js/
     */

    //地図番号
    var $no = 0;

    //吹き出し画像サイズ
    var $size = 'thumbnail';

    var $is_mobile = false;

    var $app_id = '';

    /**
     * インスタンス初期化
     */
    public function __construct()
    {
        $this->app_id = get_option('wpymapphoto_appid');

        // アクションハンドラの登録
        add_action( "wp_head",          array( &$this, "onWpHead"         ));
        add_action( "the_content",      array( &$this, "onTheContent"     ));
        add_action( "wp_print_scripts", array( &$this, "onWpPrintScripts" ));

        // スクリプトハンドラの登録
        wp_register_script( "YahooMap", "https://map.yahooapis.jp/js/V1/jsapi?appid=" . $this->app_id);
    }


    /**
      * プラグインのURL取得
      *
      * @return URL.
      */
    private function getPluginDirURL()
    {
        $dirs = explode(DIRECTORY_SEPARATOR, dirname( __FILE__ ));
        $dir  = array_pop( $dirs ) . "/";
        return  WP_PLUGIN_URL . '/' . $dir;
    }


    /**
     * ヘッダー部分が設定される時に発生します。
     */
    function onWpHead()
    {
        echo <<<HTML
<style type="text/css">
.wp-ymap-photo { background:#fff;padding:4px;margin-bottom:4px }
.wp-ymap-photo .canvas { width:100%; height:382px; }
.yolp-infowindow table { border:0 !important; margin:0 !important; }
.yolp-infowindow table td { border:0 !important; padding:0 !important; }
.yolp-ymapbanner { display: none !important; }
</style>
HTML;
    }


    /**
     * 本文が設定される時に発生します。
     */
    function onTheContent($content)
    {
        global $post;

        if (!function_exists('exif_read_data')) {
            return $content . "[PLUGIN : WpYmapPhoto : Function 'exif_read_data()' not exists.]";
        }
        //画像URLプレフィックスの設定
        $upload_info = wp_upload_dir();
        $upload_url = $upload_info['baseurl'] . '/';
        //本文中の画像URL抽出
        $images = array();
        if (preg_match_all('/<img[^>]+src="([^"]+.jpg)"[^>]*>/', $content, $ma, PREG_PATTERN_ORDER)) {
            foreach($ma[1] as $v) {
                //upload_path以外はスキップ
                if (strpos($v, $upload_url) !== 0) continue;
                //サイズ情報を削除
                $orig = preg_replace('/-\d+x\d+\.jpg$/i', '.jpg', $v);
                if (!array_key_exists($orig, $images)) {
                    //キーにURLセット
                    $images[$orig] = null;
                }
            }
        }
        if (empty($images)) return $content;
        //添付画像の取得
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'orderby'        => 'post_date',
            'post_parent'    => $post->ID,
        );
        $atts = get_posts($args);
        if (empty($atts)) return $content;
        foreach($atts as $att) {
            if (array_key_exists($att->guid, $images)) {
                //本文中に含まれる画像なら表示に利用する
                $images[$att->guid] = $att;
            }
        }
        //経緯度取得
        $ar = array();
        foreach($images as $att) {
            //添付画像でなければスキップ
            if (is_null($att)) continue;
            //EXIF読み込み
            $meta = wp_get_attachment_metadata($att->ID);
            $file = $upload_info['basedir'] . DIRECTORY_SEPARATOR . $meta['file'];
            if (!file_exists($file)) continue;
            $row = exif_read_data( $file, 'EXIF');
            //経緯度がなければスキップ
            if (!$row) continue;
            if (!array_key_exists('GPSLatitude', $row) || !array_key_exists('GPSLongitude', $row)) continue;
            $ar[] = array(
                'id'      => $att->ID,
                'title'   => htmlspecialchars($att->post_title),
                'content' => htmlspecialchars($att->post_content),
                'datetime' => $row['DateTime'],
                'latitude' =>  round($this->normalizeGpsLocationValue( $row['GPSLatitude'] ), 6),
                'longitude' => round($this->normalizeGpsLocationValue( $row['GPSLongitude'] ), 6),
            );
        }
        //経緯度付き添付画像がなければ、そのまま終了
        if (empty($ar)) return $content;
        //Yahoo!地図 追加
        $this->no++;
        $l = array();
        $l[] = '<div class="wp-ymap-photo">';
        $l[] = '  <div id="wp-ymap-photo' . $this->no . '" class="canvas">map</div>';
        $l[] = '</div>';
        $l[] = '<script type="text/javascript">';
        $l[] = '(function() {';
        $l[] = '  var mymap = new Y.Map(document.getElementById("wp-ymap-photo' . $this->no . '"));';
        $l[] = '  var cs = new Y.ScaleControl();';
        $l[] = '  var cz = new Y.ZoomControl();';
        $l[] = '  mymap.addControl(cs);';
        $l[] = '  mymap.addControl(cz);';
        $l[] = '  var z = 15;';
        //写真が複数枚あれば、マーカー位置を記録する
        $l[] = '  var points = [];'; //地理座標内の矩形
        $l[] = '  var p;';
        $l[] = '  var markers = [];';
        $l[] = '  var infowins = [];';
        foreach($ar as $v) {
            $l[] = sprintf('  p = new Y.LatLng(%s, %s);', $v['latitude'], $v['longitude']);
            $l[] = '  points.push(p);';
            list($src, $width, $height) = wp_get_attachment_image_src($v['id'], $this->size);
            $photo = sprintf('<img src="%s" alt="%s" style="display:block;margin:2px 0"/>', $src, $v['title']);
            $l[] = '  var m = new Y.Marker(p, {title : "' . esc_attr($v['title']) .'"});';
            $l[] = '  m.bindInfoWindow("' . str_replace('"', "'", $photo) .'");';
            $l[] = '  markers.push(m);';
        }
        //複数枚写真があれば、マーカーが全て収まるように地図を
        //写真が一枚の場合は、ズーム値、地図センター固定
        $l[] = '  var bounds = null;';
        $l[] = '  var center = points[0];';
        $l[] = '  if (points.length > 1) {';

        $l[] = '    bounds = new Y.LatLngBounds(points[0], points[0]);'; //sw, ne
        $l[] = '    for (var i = 1; i < points.length-1; i++) {';
        $l[] = '      bounds.extend(points[i]);';
        $l[] = '    }';
        $l[] = '    center = bounds.getCenter();';
        $l[] = '  }';
        $l[] = '  if (bounds != null ) {z = mymap.getBoundsZoomLevel(bounds);}';
        $l[] = '  mymap.drawMap(center, z, Y.LayerSetId.NORMAL);';
        $l[] = '  for (var m in markers) {mymap.addFeature(markers[m]);}';
        $l[] = '})();';// 無名関数を即時実行
        $l[] = '</script>';
        return $content . implode("\n", $l);
    }


    /**
     * WordPress のスクリプト出力が行われる時に発生します。
     */
    public function onWpPrintScripts()
    {
        wp_enqueue_script( "YahooMap" );
    }


    /**
     * EXIF の GPS の位置情報 ( 緯度・経度 ) を正規化します。
     *
     * @param   $values GPS の位置情報を示す配列 ( 度・分・秒 )。
     * @return  成功時は正規化された値。失敗時は false。
     * @link http://akabeko.me/blog/tag/geotag/page/2/
     */
    function normalizeGpsLocationValue( $values )
    {
        if( count( $values ) != 3 ) { return false; }

        $degrees = $this->normalizeGpsValue( $values[ 0 ] );
        if ($degrees === false ) { return false; }

        $minutes = $this->normalizeGpsValue( $values[ 1 ] );
        if ($minutes === false ) { return false; }

        $seconds = $this->normalizeGpsValue( $values[ 2 ] );
        if ($seconds === false) { return false; }

        return $degrees + ( $minutes / 60.0 ) + ( $seconds / 3600 );
    }

    /**
     * EXIF の GPS 情報を正規化します。
     *
     * @param   $values GPS 情報。
     * @return  成功時は正規化した値。失敗時は false。
     * @link http://akabeko.me/blog/tag/geotag/page/2/
     */
    function normalizeGpsValue( $value )
    {
        // GPS 情報は "35/1" のような書式となるので、スラッシュで分割する
        $fraction = explode( "/", $value );
        if( count( $fraction ) != 2 ) { return false; }

        $numerator   = ( double )$fraction[ 0 ];
        $denominator = ( double )$fraction[ 1 ];

        return ( $numerator / $denominator );
    }
}


// プラグインのインスタンス生成
if (class_exists('WpYmapPhoto')) {
	$wpYmapPhoto = new WpYmapPhoto();
}
