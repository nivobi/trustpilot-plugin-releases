import { useState, useEffect } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

function StarRow( { count } ) {
	return (
		<span className="tp-stars" aria-label={ `${ count } stars` }>
			{ [ 1, 2, 3, 4, 5 ].map( ( i ) => (
				<span key={ i } className={ i <= count ? 'tp-star tp-star--filled' : 'tp-star' }>
					★
				</span>
			) ) }
		</span>
	);
}

function Avatar( { name } ) {
	const initial = ( name || '?' ).charAt( 0 ).toUpperCase();
	return <div className="tp-avatar">{ initial }</div>;
}

function truncate( str, max ) {
	if ( ! str || str.length <= max ) return str;
	return str.slice( 0, max ).trimEnd() + '…';
}

function formatDate( iso ) {
	if ( ! iso ) return '';
	return new Date( iso ).toLocaleDateString( undefined, { dateStyle: 'medium' } );
}

export default function PreviewModal( { preset, onClose } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/tp/v1/presets/${ preset.slug }/preview?preview_limit=10` } )
			.then( setData )
			.catch( ( e ) => setError( e.message || 'Failed to load preview.' ) );
	}, [ preset.slug ] );

	const title = `Preview: ${ preset.name || preset.slug }`;

	return (
		<Modal title={ title } onRequestClose={ onClose } className="tp-preview-modal">
			{ ! data && ! error && (
				<div className="tp-preview-loading">
					<Spinner />
					<span>Loading reviews…</span>
				</div>
			) }

			{ error && (
				<div className="notice notice-error inline">
					<p>{ error }</p>
				</div>
			) }

			{ data && (
				<>
					<p className="tp-preview-meta">
						{ data.count === 0
							? 'No reviews match this preset.'
							: `Showing ${ Math.min( 10, data.reviews.length ) } of ${ data.count } matching review${ data.count !== 1 ? 's' : '' }.`
						}
					</p>

					{ data.reviews.length === 0 ? (
						<div className="tp-empty-state" style={ { marginTop: 0 } }>
							<p>Try broadening your keyword or star filters.</p>
						</div>
					) : (
						<div className="tp-review-list">
							{ data.reviews.map( ( review ) => (
								<div key={ review.review_id } className="tp-review-card">
									<div className="tp-review-card__header">
										<Avatar name={ review.author } />
										<div className="tp-review-card__meta">
											<strong className="tp-review-card__author">
												{ review.author || 'Anonymous' }
											</strong>
											<StarRow count={ parseInt( review.stars, 10 ) } />
										</div>
										<span className="tp-review-card__date">
											{ formatDate( review.published_at ) }
										</span>
									</div>
									{ review.title && (
										<div className="tp-review-card__title">{ review.title }</div>
									) }
									<p className="tp-review-card__body">
										{ truncate( review.body, 220 ) }
									</p>
								</div>
							) ) }
						</div>
					) }
				</>
			) }
		</Modal>
	);
}
