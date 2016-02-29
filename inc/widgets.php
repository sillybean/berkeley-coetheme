<?php

// helper function for the feed display widget
function scl_not_empty( $val ) {
	$val = str_replace( array( "\n", "\r", "\t" ), '', $val );
    return !empty( $val );
}

// initialize all custom widgets
function berkeley_widgets_init() {
	register_widget( 'WP_Nav_Menu_Dropdown_Widget' );
	register_widget( 'Berkeley_Calendar_XML_Widget' );
}

add_action( 'widgets_init', 'berkeley_widgets_init' );


// Widget for nav menu <select> list

 class Berkeley_Calendar_XML_Widget extends WP_Widget {

	/**
	 * Sets up a new Custom Menu widget instance.
	 *
	 * @since 3.0.0
	 * @access public
	 */
	public function __construct() {
		$widget_ops = array( 'description' => __('Display events from the Berkeley Events Calendar.') );
		parent::__construct( 'berkeley_calendar_xml', __('Berkeley Calendar Feed'), $widget_ops );
	}

	/**
	 * Outputs the content for the current Custom Menu widget instance.
	 *
	 * @since 3.0.0
	 * @access public
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance Settings for the current Custom Menu widget instance.
	 */
	public function widget( $args, $instance ) {
		
		$response = wp_remote_request(
		     esc_url( $instance['url'] ),
		     array( 'ssl_verify' => true )
		);
		
		if ( is_wp_error( $response ) )
		    return current_user_can( 'manage_options' ) ? $response->get_error_message() : '';

		$content = trim( wp_remote_retrieve_body( $response ) );
		$content = new SimpleXMLElement( $content );
		
		$instance['title'] = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo $args['before_widget'];

		if ( !empty($instance['title']) )
			echo $args['before_title'] . $instance['title'] . $args['after_title'] . '<ul>';

		$eventlist = array();
		
		if ( isset( $content->Event[0] ) )
			$format = 'xml';
		elseif ( isset( $content->channel ) )
			$format = 'rss';
		else
			break;
		
		if ( !isset( $instance['num'] ) || empty( $instance['num'] ) ) {
			if ( 'xml' == $format )
				$instance['num'] = count( $content->Event );
			else
				$instance['num'] = count( $content->channel->item );
		}
		
		for ( $i = 0; $i < $instance['num']; $i++ ) {
			
			if ( 'xml' == $format ) {
				$event = $content->Event[$i];
				
				if ( isset( $event->DateTime->RecurrenceDates ) )
					$date = $event->DateTime->RecurrenceDates->RecurrenceDate[0]->DistinctDate->Date;
				else 
					$date = $event->DateTime->StartDate;
				
				$url = sprintf( 'http://events.berkeley.edu/?event_ID=%d&date=%s&tab=all_events', $event->ID, $date );
				
				$title = sprintf( '<a href="%s">%s</a>', $url, $event->Title );
			}	
			else {
				$event = $content->channel->item[$i];
				$date = false;
				$url = $event->guid;
				$title = sprintf( '<a href="%s">%s</a>', $url, $event->title );
			}
			
			
			printf( '<li class="event"> <h4 class="event-title">%s</h4>', $title );
			
			if ( 'xml' == $format && $instance['display']['date'] ) {
				$fulldate = date_create_from_format( 'Y-m-d', $date );
				$formatteddate = date_format( $fulldate, get_option( 'date_format' ) );
				$times = '';
				if ( $instance['display']['times'] ) {
					if ( isset( $event->DateTime->FormattedStartTime ) && isset( $event->DateTime->FormattedEndTime ) )
						$times = $event->DateTime->FormattedStartTime . '&ndash;' . $event->DateTime->FormattedEndTime;
					if ( $formatteddate && $times )
						$times = ', ' . $times;
				}
				printf( '<p class="event-date">%s</p>', $formatteddate . $times );
			}
			
			if ( 'xml' == $format && $instance['display']['locations'] && isset( $event->Locations ) ) {
				$eventlocations = array();	
				foreach ( $event->Locations->Location as $location ) {
					 $eventlocations[] = $location->LocationName;
				}
				printf( '<p class="event-locations">%s</p>', implode( ', ', array_filter( $eventlocations ) ) );
			}
			
			if ( 'xml' == $format && $instance['display']['speaker'] && isset( $event->Performers ) ) {
				$speakers = array();	
				foreach ( $event->Performers->Performer as $performer ) {
					$speakerinfo = array();
					$speakerinfo[] = $performer->Name->FullName;
					if ( isset($performer->ProfessionalAffiliations) ) {
						foreach ( $performer->ProfessionalAffiliations->ProfessionalAffiliation as $affiliation ) {
							$speakerinfo[] = $affiliation->JobTitles->JobTitle;
							$speakerinfo[] = $affiliation->OrganizationName;
						}
					}
					$speakers[] = implode( ', ', array_filter( $speakerinfo ) );
				}
				printf( '<p class="event-speakers">%s</p>', implode( '<br/>', array_filter( $speakers ) ) );
			}
			
			if ( $instance['display']['desc'] ) {
				if ( 'xml' == $format && isset( $event->ShortDescription ) ) 	
					printf( '<p class="event-desc">%s</p>', $event->ShortDescription );
				elseif ( isset( $event->description ) ) {
					$desc = $event->description;
					if ( $instance['trim'] ) {
						$desc = str_replace( '<br />', "\n\n", $desc );
						$split = "\n";
						$desc = explode( $split, $desc );
						$desc = array_slice( array_filter( $desc, 'scl_not_empty' ), 0, $instance['trim'] );
						$desc = implode( '<br /><br />', $desc );
					}
					printf( '<p class="event-desc">%s</p>', $desc );
				}
			}
			
			echo '</li>';
			
		} // for

		echo '</ul>' . $args['after_widget'];
	}


	public function update( $new_instance, $old_instance ) {
		$instance = array();
		if ( ! empty( $new_instance['title'] ) ) {
			$instance['title'] = sanitize_text_field( stripslashes( $new_instance['title'] ) );
		}
		if ( ! empty( $new_instance['url'] ) ) {
			$instance['url'] = $new_instance['url'];
		}
		if ( ! empty( $new_instance['trim'] ) ) {
			$instance['trim'] = (int)$new_instance['trim'];
		}
		if ( ! isset( $new_instance['num'] ) ) { // allow 0
			$instance['num'] = 5;
		}
		else {
			$instance['num'] = (int)$new_instance['num'];
		}
		if ( ! empty( $new_instance['display'] ) ) {
			$instance['display'] = $new_instance['display'];
		}
		return $instance;
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$url = isset( $instance['url'] ) ? $instance['url'] : '';
		$num = isset( $instance['num'] ) ? $instance['num'] : '';
		$trim = isset( $instance['trim'] ) ? $instance['trim'] : '';
		$display = isset( $instance['display'] ) ? $instance['display'] : array();

		?>
		<div class="events-widget-form-controls">
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ) ?></label>
				<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo esc_attr( $title ); ?>"/>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'url' ); ?>"><?php _e( 'Events Feed URL (RSS or XML):' ) ?></label>
				<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'url' ); ?>" name="<?php echo $this->get_field_name( 'url' ); ?>" value="<?php echo esc_attr( $url ); ?>"/>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'num' ); ?>"><?php _e( 'Number of events to display:' ) ?></label>
				<input type="text" class="inline" size="1" id="<?php echo $this->get_field_id( 'num' ); ?>" name="<?php echo $this->get_field_name( 'num' ); ?>" value="<?php echo esc_attr( $num ); ?>"/><br />
				<span class="description"><?php _e('Enter 0 to display all available events.'); ?></span>
			</p>
			<p>	<?php _e( 'Display: '); ?> <br />
				
				<label>	<input type="checkbox" class="widefat" value="1" name="<?php echo $this->get_field_name( 'display' ); ?>[date]" <?php checked( 1, $display['date'] ); ?> /> <?php _e( 'Date *' ) ?></label> 
				<br>
				<label>	<input type="checkbox" class="widefat" value="1" name="<?php echo $this->get_field_name( 'display' ); ?>[times]" <?php checked( 1, $display['times'] ); ?> /> <?php _e( 'Start and End Times *' ) ?></label>
				<br>
				<label>	<input type="checkbox" class="widefat" value="1" name="<?php echo $this->get_field_name( 'display' ); ?>[locations]" <?php checked( 1, $display['locations'] ); ?> /> <?php _e( 'Location(s) *' ) ?></label>
				<br>
				<label>	<input type="checkbox" class="widefat" value="1" name="<?php echo $this->get_field_name( 'display' ); ?>[speaker]" <?php checked( 1, $display['speaker'] ); ?> /> <?php _e( 'Speaker(s) *' ) ?></label>
				<br>
				<label>	<input type="checkbox" class="widefat" value="1" name="<?php echo $this->get_field_name( 'display' ); ?>[desc]" <?php checked( 1, $display['desc'] ); ?> /> <?php _e( 'Description' ) ?></label>
			</p>
			<p><span class="description"><?php _e( '* Options for XML feeds only.' ); ?></span></p>
			<p>
				<label for="<?php echo $this->get_field_id( 'num' ); ?>"><?php _e( 'Trim RSS descriptions to ' ) ?>
				<input type="text" class="inline" size="1" id="<?php echo $this->get_field_id( 'trim' ); ?>" name="<?php echo $this->get_field_name( 'trim' ); ?>" value="<?php echo esc_attr( $trim ); ?>"/> <?php _e( ' paragraphs' ); ?></label>
			</p>
			
		</div>
		<?php
	}
}


// Widget for nav menu <select> list

 class WP_Nav_Menu_Dropdown_Widget extends WP_Widget {

	/**
	 * Sets up a new Custom Menu widget instance.
	 *
	 * @since 3.0.0
	 * @access public
	 */
	public function __construct() {
		$widget_ops = array( 'description' => __('Add a custom menu dropdown to your sidebar.') );
		parent::__construct( 'nav_menu_dropdown', __('Custom Menu Dropdown'), $widget_ops );
	}

	/**
	 * Outputs the content for the current Custom Menu widget instance.
	 *
	 * @since 3.0.0
	 * @access public
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title',
	 *                        'before_widget', and 'after_widget'.
	 * @param array $instance Settings for the current Custom Menu widget instance.
	 */
	public function widget( $args, $instance ) {
		// Get menu
		$nav_menu = ! empty( $instance['nav_menu'] ) ? wp_get_nav_menu_object( $instance['nav_menu'] ) : false;

		if ( !$nav_menu )
			return;

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$instance['title'] = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

		echo $args['before_widget'];

		if ( !empty($instance['title']) )
			echo $args['before_title'] . $instance['title'] . $args['after_title'];

		$nav_menu_args = array(
			'fallback_cb' => '',
			'menu'        => $nav_menu,
			'walker'         => new Walker_Nav_Menu_Dropdown(),
			'items_wrap'     => '<div class="select-menu"><form><select onchange="if (this.value) window.location.href=this.value">%3$s</select></form></div>',
		);

		/**
		 * Filter the arguments for the Custom Menu widget.
		 *
		 * @since 4.2.0
		 * @since 4.4.0 Added the `$instance` parameter.
		 *
		 * @param array    $nav_menu_args {
		 *     An array of arguments passed to wp_nav_menu() to retrieve a custom menu.
		 *
		 *     @type callable|bool $fallback_cb Callback to fire if the menu doesn't exist. Default empty.
		 *     @type mixed         $menu        Menu ID, slug, or name.
		 * }
		 * @param stdClass $nav_menu      Nav menu object for the current menu.
		 * @param array    $args          Display arguments for the current widget.
		 * @param array    $instance      Array of settings for the current widget.
		 */
		wp_nav_menu( apply_filters( 'widget_nav_menu_args', $nav_menu_args, $nav_menu, $args, $instance ) );

		echo $args['after_widget'];
	}

	/**
	 * Handles updating settings for the current Custom Menu widget instance.
	 *
	 * @since 3.0.0
	 * @access public
	 *
	 * @param array $new_instance New settings for this instance as input by the user via
	 *                            WP_Widget::form().
	 * @param array $old_instance Old settings for this instance.
	 * @return array Updated settings to save.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		if ( ! empty( $new_instance['title'] ) ) {
			$instance['title'] = sanitize_text_field( stripslashes( $new_instance['title'] ) );
		}
		if ( ! empty( $new_instance['nav_menu'] ) ) {
			$instance['nav_menu'] = (int) $new_instance['nav_menu'];
		}
		return $instance;
	}

	/**
	 * Outputs the settings form for the Custom Menu widget.
	 *
	 * @since 3.0.0
	 * @access public
	 *
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$nav_menu = isset( $instance['nav_menu'] ) ? $instance['nav_menu'] : '';

		// Get menus
		$menus = wp_get_nav_menus();

		// If no menus exists, direct the user to go and create some.
		?>
		<p class="nav-menu-widget-no-menus-message" <?php if ( ! empty( $menus ) ) { echo ' style="display:none" '; } ?>>
			<?php
			if ( isset( $GLOBALS['wp_customize'] ) && $GLOBALS['wp_customize'] instanceof WP_Customize_Manager ) {
				$url = 'javascript: wp.customize.panel( "nav_menus" ).focus();';
			} else {
				$url = admin_url( 'nav-menus.php' );
			}
			?>
			<?php echo sprintf( __( 'No menus have been created yet. <a href="%s">Create some</a>.' ), esc_attr( $url ) ); ?>
		</p>
		<div class="nav-menu-widget-form-controls" <?php if ( empty( $menus ) ) { echo ' style="display:none" '; } ?>>
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ) ?></label>
				<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo esc_attr( $title ); ?>"/>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'nav_menu' ); ?>"><?php _e( 'Select Menu:' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'nav_menu' ); ?>" name="<?php echo $this->get_field_name( 'nav_menu' ); ?>">
					<option value="0"><?php _e( '&mdash; Select &mdash;' ); ?></option>
					<?php foreach ( $menus as $menu ) : ?>
						<option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $nav_menu, $menu->term_id ); ?>>
							<?php echo esc_html( $menu->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
	}
}



// Walker class to display menu items in <select> list

class Walker_Nav_Menu_Dropdown extends Walker_Nav_Menu {
	function start_lvl(&$output, $depth){
		$indent = str_repeat("\t", $depth); // don't output children opening tag (`<ul>`)
	}
	function end_lvl(&$output, $depth){
		$indent = str_repeat("\t", $depth); // don't output children closing tag
	}
	/**
	* Start the element output.
	*
	* @param  string $output Passed by reference. Used to append additional content.
	* @param  object $item   Menu item data object.
	* @param  int $depth     Depth of menu item. May be used for padding.
	* @param  array $args    Additional strings.
	* @return void
	*/
	function start_el(&$output, $item, $depth, $args) {
 		$url = '#' !== $item->url ? $item->url : '';
 		$output .= '<option value="' . $url . '">' . $item->title;
	}	
	function end_el(&$output, $item, $depth){
		$output .= "</option>\n"; // replace closing </li> with the option tag
	}
}

// Add background color selector to specific widgets
// cf. http://ednailor.com/2011/01/24/adding-custom-css-classes-to-sidebar-widgets/

function berkeley_editor_widget_class_form_extend( $instance, $widget ) {
	if ( 'wp_editor_widget' !== $widget->id_base )
		return $instance;
	
	if ( !isset( $instance['classes'] ) )
		$instance['classes'] = '';
	
	$myclasses = array( 0 => 'None', 'bold' => 'Bold', 'subtle' => 'Subtle' );
	$fieldname = sprintf( 'widget-%s[%d][classes]', $widget->id_base, $widget->number );
	$field_id = sprintf( 'widget-%s-%d-classes', $widget->id_base, $widget->number );
	
	$row = '<p>';
	$row .= sprintf( '<label for="%s">%s</label>', $field_id, 'Background color:' );
	$row .= sprintf( '<select name="%s" id="%s" class="widefat">', $fieldname, $field_id );

	foreach( $myclasses as $class => $label ) {
		$row .= sprintf( '<option value="%s" %s >%s</option>', $class, selected( $class, $instance['classes'], false ), $label );
	}
	$row .= '</select></p>';
	
	echo $row;
	return $instance;
}

add_filter( 'widget_form_callback', 'berkeley_editor_widget_class_form_extend', 10, 2 );

function berkeley_editor_widget_class_update( $instance, $new_instance ) {
	if ( isset( $new_instance['classes'] ) && in_array( $new_instance['classes'], array( 'bold', 'subtle' ) ) )
		$instance['classes'] = $new_instance['classes'];
	return $instance;
}

add_filter( 'widget_update_callback', 'berkeley_editor_widget_class_update', 10, 2 );

function berkeley_editor_widget_class_params( $params ) {
	global $wp_registered_widgets;
	$widget_id    = $params[0]['widget_id'];
	$widget_obj   = $wp_registered_widgets[$widget_id];
	$widget_opt   = get_option($widget_obj['callback'][0]->option_name);
	$widget_num   = $widget_obj['params'][0]['number'];

	if ( isset($widget_opt[$widget_num]['classes']) && !empty($widget_opt[$widget_num]['classes']) )
		$params[0]['before_widget'] = str_replace( 'class="widget ', 'class="widget ' . $widget_opt[$widget_num]['classes'] . ' ', $params[0]['before_widget'] );

	return $params;
}
add_filter( 'dynamic_sidebar_params', 'berkeley_editor_widget_class_params' );