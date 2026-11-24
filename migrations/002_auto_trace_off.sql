-- Skip tracing is manual by default: only leads a user selects are traced.
UPDATE settings SET `value` = '0' WHERE `key` = 'auto_trace';
