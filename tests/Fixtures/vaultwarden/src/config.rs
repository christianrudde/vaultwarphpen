macro_rules! make_config {
    () => {};
}

make_config! {
    jobs {
        /// Trash purge schedule |> Cron schedule of the job that checks for trashed items to delete permanently.
        /// Defaults to daily. Set blank to disable this job.
        trash_purge_schedule:   String, false,  def,    "0 5 0 * * *".to_owned();
    },
    smtp: _enable_smtp {
        /// Enabled
        _enable_smtp:                  bool,   true,   def,     true;
        /// Secure SMTP |> ("starttls", "force_tls", "off") Enable a secure connection
        smtp_security:                 String, true,   auto,    |c| convert(c.smtp_ssl); // TODO: drop the conversion
        /// Port
        smtp_port:                     u16,    true,   auto,    |c| if c.smtp_security == *"force_tls" {465} else {25};
    },
}
