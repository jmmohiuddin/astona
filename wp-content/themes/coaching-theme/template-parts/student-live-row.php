<?php
/**
 * One live class row with the Join button. Args: item (CC_Portal_Courses live item), next_text (optional).
 * No meeting URL is ever rendered; student-live.js fetches it on click.
 */
$item = $args['item'] ?? array();
if ( empty( $item ) ) {
	return;
}
$state_labels = array(
	'inactive' => 'Not open yet',
	'active'   => 'Live now',
	'ended'    => 'Class ended',
);
?>
<li class="live-row">
	<div class="live-row__info">
		<strong><?php echo esc_html( $item['title'] ); ?></strong>
		<span class="muted small"><?php echo esc_html( $item['when_text'] ); ?><?php echo '' !== $item['batch_label'] ? ' · ' . esc_html( $item['batch_label'] ) : ''; ?></span>
	</div>
	<button type="button" class="live-join live-join--<?php echo esc_attr( $item['state'] ); ?>"
		data-live-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
		data-starts="<?php echo esc_attr( (string) $item['start_ts'] ); ?>"
		data-ends="<?php echo esc_attr( (string) $item['end_ts'] ); ?>"
		data-title="<?php echo esc_attr( $item['title'] ); ?>"
		data-start-text="<?php echo esc_attr( wp_date( 'g:i A', $item['start_ts'] ) ); ?>"
		data-next="<?php echo esc_attr( (string) ( $args['next_text'] ?? '' ) ); ?>"
		aria-label="<?php echo esc_attr( $item['title'] . ': ' . ( $state_labels[ $item['state'] ] ?? '' ) ); ?>"><?php echo esc_html( $state_labels[ $item['state'] ] ?? '' ); ?></button>
</li>
