<?php
/*
Plugin Name: Videntity
Plugin URI: http://singpolyma.net/plugins/videntity/
Description: Allows you to display elements from your videntity.org profile on Wordpress.  Allows you to import Videntity friends to WordPress.  Allows you to import your Videntity profile to WordPress.
Version: 0.3
Author: Stephen Paul Weber
Author URI: http://singpolyma.net/
*/

/* Released under the GPL, any version. */

$videntity_profile = array();

if( !function_exists( 'normalize_url' ) )
{
    function normalize_url( $url )
    {
        $url = trim( $url );
        
        $parts = parse_url( $url );
        $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : null;

        if( !$scheme )
        {
            $url = 'http://' . $url;
            $parts = parse_url( $url );
        }

        $path = isset( $parts['path'] ) ? $parts['path'] : null;
        
        if( !$path )
            $url .= '/';
        
        return $url;
    }
}

function videntity_extract_by_id($xml,$id,$aatag='') {

   $rtrn = array();

   $theParser = xml_parser_create();
   $err = xml_parse_into_struct($theParser,$xml,$vals);
   xml_parser_free($theParser);
   if(!$vals || !count($vals)) return array();

   $flattento = false;
   $flattentag = '';
   $subflatten = 0;

   foreach($vals as $el) {
      $isopen = ($el['type'] == 'open' || $el['type'] == 'complete');//for readability
      $isclose = ($el['type'] == 'close' || $el['type'] == 'complete');

               if($flattento !== false) {//if flattening tags
                  if($isopen && $flattentag == $el['tag']) {$subflatten++;}
                  if($isclose && $flattentag == $el['tag']) {
                     if($subflatten > 0) {
                        $subflatten--;
                     } else {
                        $flattento .= '</'.strtolower($flattentag).'>';
                        $rtrn[] = $flattento;
                        $flattentag = '';
                        $subflatten = 0;
                        unset($flattento);
                        $flattento = false;
                     }//end if-else subflatten
                  }//end if isclose &&
                  if($flattento !== false) {//flattento may have changed in previous section
                     $emptytag = false;//assume not an empty tag
                     if($isopen) {//if opening tag
                        $flattento .= ' <'.strtolower($el['tag']);//add open tag
                        if($el['attributes']) {//if attributes
                           foreach($el['attributes'] as $id => $val) {//loop through and add
                              $flattento .= ' '.strtolower($id).'="'.htmlspecialchars($val).'"';
                           }//end foreach
                        }//end if attributes
                        $emptytag = ($el['type'] == 'complete' && !$el['value']);//is emptytag?
                        $flattento .= $emptytag?' />':'>';//end tag
                        if($el['value']) {$flattento .= htmlspecialchars($el['value']);}//add contents, if any
                     }//end if isopen
                     if($el['type'] == 'cdata') {//if cdata
                        $flattento .= htmlspecialchars($el['value']);//add data
                     }//end if cdata
                     if($isclose) {//if closing tag
                        if(!$emptytag) {$flattento .= '</'.strtolower($el['tag']).'>';}//if not emptytag, write out end tag
                     }//end if isclose
                  }//end if flattento
                  continue;
               }//end if flattento

      if($isopen && (($id && $el['attributes']['ID'] == $id) || (!$id && $el['tag'] == strtoupper($aatag)))) {//if we've found the right class
         $flattento = '<'.strtolower($el['tag']);
         if($el['attributes']) {
            foreach($el['attributes'] as $att => $val)
               $flattento .= ' '.htmlspecialchars(strtolower($att)).'="'.htmlspecialchars($val).'"';
         }//end if attributes
         $flattento .= '>'.htmlspecialchars($el['value']);
         $flattentag = $el['tag'];
         $subflatten = 0;
         if($isclose) {
            $flattento .= '</'.strtolower($flattentag).'>';
            $rtrn[] = $flattento;
            $flattentag = '';
            unset($flattento);
            $flattento = false;
            $subflatten = 0;
         }//end if isclose
      }//end if theclass

   }//end foreach vals as el

   return $rtrn;

}//end function videntity_extract_by_id

function videntity_extract_friends($xml) {

   $rtrn = array();

   $theParser = xml_parser_create();
   $err = xml_parse_into_struct($theParser,$xml,$vals);
   xml_parser_free($theParser);
   if(!$vals || !count($vals)) return array();

   foreach($vals as $el) {
      $isopen = ($el['type'] == 'open' || $el['type'] == 'complete');//for readability
      $isclose = ($el['type'] == 'close' || $el['type'] == 'complete');

      if($isopen && $el['tag'] == 'A') {//all links
         $tmp = array();
         $tmp['fn'] = trim($el['value']);
         $tmp['relationship'] = explode(' ',trim($el['attributes']['REL']));
         $tmp['url'] = array();
         $tmp['url'][] = trim($el['attributes']['HREF']);
         if(strstr($tmp['url'][0],'/profile/')) {
            $tmp2 = array_reverse(explode('/',$tmp['url'][0]));
            if(!$tmp2[0]) $tmp2[0] = $tmp2[1];
            $tmp['url'][] = normalize_url(urldecode($tmp2[0]));
         }//end if strstr /profile/
         $rtrn[] = $tmp;
      }//end if theclass

   }//end foreach vals as el

   return $rtrn;

}//end function videntity_extract_friends

function videntity_load($videntity,$loadglobal=true) {

   if($loadglobal) global $videntity_profile;

   require_once dirname(__FILE__).'/hkit.class.php';

   if(ini_get('allow_url_fopen')) {
      $page = file_get_contents('http://videntity.org/profile/'.$videntity);
   } else if(function_exists('curl_init')) {
      $curl = curl_init('http://videntity.org/profile/'.$videntity);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.0.4) Gecko/20060508 Firefox/2.0');
      $page = curl_exec($curl);
      curl_close($curl);
   } else {
      echo '<br /><b>[Videntity] allow_url_fopen or cURL required.</b><br />';
      return array();
   }//end if-elses allow_url_fopen / curl

   if(function_exists('tidy_clean_repair'))
      $page = tidy_clear_repair($page);
   $page = str_replace('&nbsp;','&#160;',$page);

   $h = new hKit;
   @$hcard = $h->getByString('hcard', $page);

   $videntity_profile = $hcard[0];

   $video = videntity_extract_by_id($page,'video_pick');
   $video = videntity_extract_by_id($video[0],false,'object');
   $videntity_profile['video'] = $video[0];

   $friends = videntity_extract_by_id($page,'relations_out_formatted');
   $friends = videntity_extract_friends($friends[0]);
   $videntity_profile['friends'] = $friends;

   return $videntity_profile;

}//end function videntity_load

function videntity_get($field,$echo=true,$profile='') {
   if(!$profile) {global $videntity_profile; $profile = $videntity_profile;}
   if($echo) echo $profile[$field];
   return $profile[$field];
}//end function videntity_get

function videntity_fn($echo=true,$profile='') {
   return videntity_get('fn',$echo,$profile);
}//end function videntity_fn

function videntity_nickname($echo=true,$profile='') {
   return videntity_get('nickname',$echo,$profile);
}//end function videntity_nickname

function videntity_given_name($echo=true,$profile='') {
   $name = videntity_get('n',$echo,$profile);
   return $name['given-name'];
}//end function videntity_given_name

function videntity_additional_name($echo=true,$profile='') {
   $name = videntity_get('n',$echo,$profile);
   return $name['additional-name'];
}//end function videntity_additional_name

function videntity_family_name($echo=true,$profile='') {
   $name = videntity_get('n',$echo,$profile);
   return $name['family-name'];
}//end function videntity_family_name

function videntity_url($echo=true,$profile='') {
   return videntity_get('url',$echo,$profile);
}//end function videntity_url

function videntity_photo($echo=true,$profile='') {
   return videntity_get('photo',$echo,$profile);
}//end function videntity_photo

function videntity_video($echo=true,$profile='') {
   return videntity_get('video',$echo,$profile);
}//end function videntity_video

function videntity_note($echo=true,$profile='') {
   return videntity_get('note',$echo,$profile);
}//end function videntity_note

/* IMPORT VIDENTITY FRIENDS */
function videntity_process_forms() {
   if(isset($_REQUEST['videntity_import_submit'])) {
      global $user_level;
      global $user_ID;
      global $userdata;
      global $wpdb;
      get_currentuserinfo();
      if(!$user_ID || $user_level <  8) die('Nice try, you cheeky monkey!');
      videntity_load($_REQUEST['username']);
   }//end if submit
   if(isset($_REQUEST['videntity_import_submit']) && isset($_REQUEST['videntity_import_friends'])) {
      foreach(videntity_get('friends',false) as $friend) {
         if($lid = $wpdb->get_var('SELECT link_id FROM '.$wpdb->links.' WHERE link_url="'.$wpdb->escape(normalize_url(stripslashes($friend['url'][1]))).'"')) {
            $wpdb->query('UPDATE '.$wpdb->links.' SET link_rel="'.$wpdb->escape(implode(' ',$friend['relationship'])).'", link_name="'.$wpdb->escape($friend['fn']).'" WHERE link_id='.$lid);
         } else {
            $wpdb->query('INSERT INTO '.$wpdb->links.' (link_rel,link_name,link_url,link_visible,link_owner) VALUES ("'.$wpdb->escape(implode(' ',$friend['relationship'])).'","'.$wpdb->escape($friend['fn']).'","'.$wpdb->escape(normalize_url(stripslashes($friend['url'][1]))).'","Y",'.$user_ID.')');
            $wpdb->query('INSERT INTO '.$wpdb->link2cat.' (link_id,category_id) VALUES ('.$wpdb->insert_id.','.$_REQUEST['category'].')');
         }//end if-else get_var
      }//end foreach
   }//end if isset submit and friends
   if(isset($_REQUEST['videntity_import_submit']) && isset($_REQUEST['videntity_import_profile'])) {
      $query = '';
      if(videntity_get('email',false)) $query .= ", user_email='".$wpdb->escape(videntity_get('email',false))."'";
      $url = videntity_get('url',false);
      if($url && is_array($url) && $url[0]) $url = $url[0];
      if($url && !is_array($url)) $query .= ", user_url='".$wpdb->escape($url)."'";
      if(videntity_get('fn',false)) $query .= ", display_name='".$wpdb->escape(videntity_get('fn',false))."'";
      $query = 'UPDATE '.$wpdb->users.' SET'.substr($query,1,strlen($query)).' WHERE ID='.$user_ID;
      $wpdb->query($query);
      if($userdata->nickname && videntity_get('nickname',false))
         $wpdb->query('UPDATE '.$wpdb->usermeta." SET meta_value='".$wpdb->escape(videntity_get('nickname',false))."' WHERE meta_key='nickname' AND user_id=".$user_ID);
      if(!$userdata->nickname && videntity_get('nickname',false))
         $wpdb->query('INSERT INTO '.$wpdb->usermeta." (meta_value,meta_key,user_id) VALUES('".$wpdb->escape(videntity_get('nickname',false))."','nickname',".$user_ID.')');
      if($userdata->first_name && videntity_given_name(false))
         $wpdb->query('UPDATE '.$wpdb->usermeta." SET meta_value='".$wpdb->escape(videntity_given_name(false))."' WHERE meta_key='first_name' AND user_id=".$user_ID);
      if(!$userdata->first_name && videntity_given_name(false))
         $wpdb->query('INSERT INTO '.$wpdb->usermeta." (meta_value,meta_key,user_id) VALUES('".$wpdb->escape(videntity_given_name(false))."','first_name',".$user_ID.')');
      if($userdata->additional_name && videntity_additional_name(false))
         $wpdb->query('UPDATE '.$wpdb->usermeta." SET meta_value='".$wpdb->escape(videntity_additional_name(false))."' WHERE meta_key='additional_name' AND user_id=".$user_ID);
      if(!$userdata->additional_name && videntity_additional_name(false))
         $wpdb->query('INSERT INTO '.$wpdb->usermeta." (meta_value,meta_key,user_id) VALUES('".$wpdb->escape(videntity_additional_name(false))."','additional_name',".$user_ID.')');
      if($userdata->last_name && videntity_family_name(false))
         $wpdb->query('UPDATE '.$wpdb->usermeta." SET meta_value='".$wpdb->escape(videntity_family_name(false))."' WHERE meta_key='last_name' AND user_id=".$user_ID);
      if(!$userdata->last_name && videntity_family_name(false))
         $wpdb->query('INSERT INTO '.$wpdb->usermeta." (meta_value,meta_key,user_id) VALUES('".$wpdb->escape(videntity_family_name(false))."','last_name',".$user_ID.')');
      if($userdata->description && videntity_get('note',false))
         $wpdb->query('UPDATE '.$wpdb->usermeta." SET meta_value='".$wpdb->escape(videntity_get('note',false))."' WHERE meta_key='description' AND user_id=".$user_ID);
      if(!$userdata->description && videntity_get('note',false))
         $wpdb->query('INSERT INTO '.$wpdb->usermeta." (meta_value,meta_key,user_id) VALUES('".$wpdb->escape(videntity_get('note',false))."','description',".$user_ID.')');
   }//end if isset submit and profile
}//end function videntity_process_forms
add_action('init', 'videntity_process_forms');

function videntity_option_page() {
      global $wpdb;
   if(isset($_REQUEST['videntity_import_submit']))
      echo '<div id="message" class="updated fade"><p><strong>Import complete!</strong></p></div>';
   echo '<div class="wrap">';
   echo '<h2>Videntity Import</h2>';
   echo '<p>Enter your Videntity username/OpenID below to import your Videntity friends/contacts, profile, or both to WordPress.</p>';
   echo '<form method="post"><div style="width:220px;margin:0 auto;text-align:right;">';
   echo 'Username/OpenID: <input type="text" name="username" /><br />';
   echo 'Category to put contact links in: <select name="category">';
   foreach($wpdb->get_results('SELECT cat_id,cat_name FROM '.$wpdb->categories.' ORDER BY link_count,cat_name',ARRAY_A) as $cat)
      echo '<option value="'.$cat['cat_id'].'">'.$cat['cat_name'].'</option>';
   echo '</select><br />';
   echo 'Import friends/contacts: <input type="checkbox" name="videntity_import_friends" checked="checked" /><br />';
   echo 'Import profile: <input type="checkbox" name="videntity_import_profile" checked="checked" /><br />';
   echo '<input type="submit" name="videntity_import_submit" value="Import" /><br />';
   echo '</div></form>';
   echo '</div>';
}//end function videntity_option_page

function videntity_tab($s) {
   add_submenu_page('options-general.php', 'Videntity', 'Videntity', 1, __FILE__, 'videntity_option_page');
   return $s;
}//end function social_networking_tab
add_action('admin_menu', 'videntity_tab');
/* END IMPORT */

?>