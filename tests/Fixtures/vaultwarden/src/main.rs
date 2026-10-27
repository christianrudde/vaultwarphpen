async fn launch_rocket(pool: db::DbPool, extra_debug: bool) -> Result<(), Error> {
    let basepath = &CONFIG.domain_path();
    let instance = rocket::custom(config)
        .mount([basepath, "/"].concat(), api::web_routes())
        .mount([basepath, "/api"].concat(), api::core_routes())
        .mount([basepath, "/events"].concat(), api::core_events_routes())
        .register([basepath, "/"].concat(), api::web_catchers());
}
