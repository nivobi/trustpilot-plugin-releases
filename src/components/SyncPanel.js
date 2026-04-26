import { useState, useEffect, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

function formatDate( iso ) {
	if ( ! iso ) return 'Never';
	return new Date( iso ).toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
}

function ProgressBar( { current, total, active, label } ) {
	if ( ! total ) return null;
	const pct = Math.min( 100, Math.round( ( current / total ) * 100 ) );

	return (
		<div className="tp-sync-progress">
			<div className="tp-progress-header">
				<span>
					{ active ? ( label || 'Syncing…' ) : 'Sync progress' }
					{ ' — ' }
					<strong>{ current.toLocaleString() }</strong> / { total.toLocaleString() } reviews
				</span>
				<span className="tp-progress-pct">{ pct }%</span>
			</div>
			<div className="tp-progress-track">
				<div
					className={ `tp-progress-fill${ active ? ' tp-progress-fill--active' : '' }` }
					style={ { width: `${ pct }%` } }
				/>
			</div>
		</div>
	);
}

export default function SyncPanel( { status, onSyncDone } ) {
	const [ syncing, setSyncing ] = useState( false );
	const [ syncMode, setSyncMode ] = useState( '' );
	const [ toast, setToast ] = useState( null );
	const pollRef = useRef( null );

	// Poll status every 3s while syncing to update the progress bar live.
	useEffect( () => {
		if ( ! syncing ) {
			if ( pollRef.current ) {
				clearInterval( pollRef.current );
				pollRef.current = null;
			}
			return;
		}
		pollRef.current = setInterval( async () => {
			try {
				const s = await apiFetch( { path: '/tp/v1/status' } );
				onSyncDone( s );
			} catch {}
		}, 3000 );
		return () => {
			if ( pollRef.current ) clearInterval( pollRef.current );
		};
	}, [ syncing ] );

	const runSync = async ( forceFull = false ) => {
		setSyncing( true );
		setSyncMode( forceFull ? 'full' : 'incremental' );
		setToast( null );

		let firstCall = true;
		let lastResult = null;

		try {
			// Auto-chain batches: each call processes 1,000 reviews.
			// Loop continues until API clears the cursor (sync_has_more = false).
			do {
				lastResult = await apiFetch( {
					path: '/tp/v1/sync',
					method: 'POST',
					data: { force_full: forceFull && firstCall },
				} );
				firstCall = false;
				onSyncDone( lastResult ); // updates total_reviews in UI after each batch
			} while ( lastResult?.sync_has_more );

			setToast( { type: 'success', message: 'Sync complete.' } );
		} catch ( e ) {
			setToast( { type: 'error', message: e.message || 'Sync failed.' } );
		} finally {
			setSyncing( false );
			setSyncMode( '' );
		}
	};

	const showProgress  = syncing || status?.sync_has_more;
	const tpTotal       = status?.tp_review_count ?? 0;
	const dbTotal       = status?.total_reviews ?? 0;
	const isFullSync    = status?.is_full_sync || ( syncing && syncMode === 'full' );
	const progressCurrent = isFullSync ? ( status?.full_sync_processed ?? 0 ) : dbTotal;
	const progressLabel   = isFullSync ? 'Re-syncing…' : 'Syncing…';

	return (
		<div className="tp-panel">
			<h2 className="tp-panel__title">Sync Status</h2>

			<div className="tp-stats-grid">
				<div className="tp-stat-card">
					<span className="tp-stat-card__value">
						{ dbTotal.toLocaleString() || '—' }
					</span>
					<span className="tp-stat-card__label">Reviews in database</span>
				</div>
				<div className="tp-stat-card">
					<span className="tp-stat-card__value tp-stat-card__value--date">
						{ formatDate( status?.last_sync ) }
					</span>
					<span className="tp-stat-card__label">Last sync</span>
				</div>
				<div className="tp-stat-card">
					<span className="tp-stat-card__value">
						{ status?.last_sync ? ( status?.last_sync_count ?? 0 ).toLocaleString() : '—' }
					</span>
					<span className="tp-stat-card__label">Last batch fetched</span>
				</div>
				<div className="tp-stat-card">
					<span className="tp-stat-card__value">{ tpTotal.toLocaleString() || '—' }</span>
					<span className="tp-stat-card__label">Trustpilot total</span>
				</div>
			</div>

			{ showProgress && tpTotal > 0 && (
				<ProgressBar current={ progressCurrent } total={ tpTotal } active={ syncing } label={ progressLabel } />
			) }

			{ status?.last_error && (
				<div className="notice notice-warning inline tp-notice">
					<p><strong>Last error:</strong> { status.last_error }</p>
				</div>
			) }

			{ toast && (
				<div className={ `notice notice-${ toast.type === 'success' ? 'success' : 'error' } is-dismissible tp-notice` }>
					<p>{ toast.message }</p>
					<button
						type="button"
						className="notice-dismiss"
						onClick={ () => setToast( null ) }
					>
						<span className="screen-reader-text">Dismiss</span>
					</button>
				</div>
			) }

			<div className="tp-panel__actions">
				<Button
					className="tp-sync-btn"
					isBusy={ syncing }
					disabled={ syncing }
					onClick={ () => runSync( false ) }
				>
					{ syncing ? 'Syncing…' : ( status?.sync_has_more ? '↻ Continue Sync' : '↻ Sync Now' ) }
				</Button>
				<button
					type="button"
					className="tp-full-sync-btn"
					disabled={ syncing }
					onClick={ () => runSync( true ) }
					title="Restart from the newest review and re-fetch everything."
				>
					⟳ Full Sync
				</button>
			</div>
		</div>
	);
}
