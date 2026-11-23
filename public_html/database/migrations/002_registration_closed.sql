-- New installations stay closed until the operator finishes the release gates.
UPDATE site_settings SET setting_value='0' WHERE setting_key='registration';
