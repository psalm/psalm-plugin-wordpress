<?php

do_action( 'foo', true );

add_filter( 'foo', function ( bool $post ) : void {
	new WP_Post( new StdClass );
}, 10, 2 );
