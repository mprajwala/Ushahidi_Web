<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Feeds Scheduler Controller
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com> 
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Feeds Controller  
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL) 
*/

/**
 * Feed type value for news
 */
define('FEED_TYPE_TEXT', 'text');

/**
 * Feed type value for videos
 */
define('FEED_TYPE_VIDEO', 'video');

/**
 * Feed type value for photos
 */
define('FEED_TYPE_PHOTO', 'photo');

class Feeds_Controller extends Controller
{
	public function __construct()
    {
        parent::__construct();
	}

        /**
         * get the feed type of the feed item. 
         */
        private function _get_feed_type( $feed_item ) {
          @$enclosures = $feed_item->get_enclosures();
          if($enclosures and ($enclosures[0]->medium == 'video' || strstr($enclosures[0]->type,'video'))){
            return FEED_TYPE_VIDEO;
          }
          if($enclosures and strstr($enclosures[0]->type,'image') || $enclosures[0]->medium == 'image'){
            return FEED_TYPE_PHOTO;
          }

          $categories = $feed_item->get_categories();
          if(!$categories || empty($categories)){
            return FEED_TYPE_TEXT;
          }
          // go through categories for the label having Report Type
          foreach($categories as $key => $category){
            if(!empty($category->label)){
                $matched = strstr($category->label,'Report type');
                if(!empty($matched)){
                  $split_array = split(':', $category->label);
                  return trim($split_array[1]);
                }
            }
          }
	  return FEED_TYPE_TEXT;
        }
	
	
	/**
	 * parse feed and send feed items to database
	 */
	public function index()
	{
		// Max number of feeds to keep
		$max_feeds = 100;
		
		// Today's Date
		$today = strtotime('now');
		
		// Get All Feeds From DB
		$feeds = ORM::factory('feed')->find_all();
		foreach ($feeds as $feed)
		{
			$last_update = $feed->feed_update;
			
			// Has it been more than 24 hours since the last update?
			if ( ((int)$today - (int)$last_update) > 86400	)	// 86400 = 24 hours
			{
				// Parse Feed URL using Feed Helper
				$feed_data = $this->_setup_simplepie( $feed->feed_url );

				foreach($feed_data->get_items(0,50) as $feed_data_item)
				{
					$title = $feed_data_item->get_title();
					$link = $feed_data_item->get_link();
					$description = $feed_data_item->get_description();
					$date = $feed_data_item->get_date();

                                        $feed_type = $this->_get_feed_type($feed_data_item);
                                        if($feed_type == FEED_TYPE_VIDEO || $feed_type == FEED_TYPE_PHOTO){
                                          $encs = $feed_data_item->get_enclosure();
                                          $link = $encs->get_link();
                                        }

					// Make Sure Title is Set (Atleast)
					if (isset($title) && !empty($title ))
					{
						// We need to check for duplicates!!!
						// Maybe combination of Title + Date? (Kinda Heavy on the Server :-( )
						$dupe_count = ORM::factory('feed_item')->where('item_title',$title)->where('item_date',date("Y-m-d H:i:s",strtotime($date)))->count_all();

						if ($dupe_count == 0) {
							$newitem = new Feed_Item_Model();
							$newitem->feed_id = $feed->id;
							$newitem->item_title = $title;
							if (isset($description) && !empty($description))
							{
								$newitem->item_description = $description;
							}
							if (isset($link) && !empty($link))
							{
								$newitem->item_link = $link;
							}
							if (isset($date) && !empty($date))
							{
								$newitem->item_date = date("Y-m-d H:i:s",strtotime($date));
							}
							// Set todays date
							else
							{
								$newitem->item_date = date("Y-m-d H:i:s",time());
							}

                                                        if (isset($feed_type) && !empty($feed_type))
                                                        {
                                                                $newitem->feed_type = $feed_type;
                                                        }

							$newitem->save();
						}
					}
				}
				
				// Get Feed Item Count
				$feed_count = ORM::factory('feed_item')->where('feed_id', $feed->id)->count_all();
				if ($feed_count > $max_feeds) {
					// Excess Feeds
					$feed_excess = $feed_count - $max_feeds;

					// Delete Excess Feeds
					foreach (ORM::factory('feed_item')
						->where('feed_id', $feed->id)
						->orderby('id', 'ASC')
						->limit($feed_excess)
						->find_all() as $del_feed)
					{
						$del_feed->delete($del_feed->id);
					}
				}

				// Set feed update date
				$feed->feed_update = strtotime('now');
				$feed->save();
			}
		}
	}
	
	
	
	/**
	 * setup simplepie
	 */
	private function _setup_simplepie( $feed_url ) {
			$data = new SimplePie();
			$data->set_feed_url( $feed_url );
			$data->enable_cache(false);
			$data->enable_order_by_date(true);
			$data->init();
			$data->handle_content_type();

			return $data;
	}
}