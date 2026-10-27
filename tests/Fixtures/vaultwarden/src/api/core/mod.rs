pub mod two_factor;
mod accounts;

pub fn routes() -> Vec<Route> {
    let mut meta_routes = routes![alive, version];

    let mut routes = Vec::new();
    routes.append(&mut accounts::routes());
    routes.append(&mut two_factor::routes());
    routes.append(&mut meta_routes);

    routes
}

pub fn events_routes() -> Vec<Route> {
    routes![post_events_collect]
}

#[get("/alive")]
fn alive(_conn: DbConn) -> Json<String> {
    crate::api::web::alive(_conn)
}

#[get("/version")]
fn version() -> Json<&'static str> {
    Json(crate::VERSION.unwrap_or_default())
}

#[post("/collect", format = "application/json", data = "<data>")]
async fn post_events_collect(data: Json<Vec<EventCollection>>, headers: Headers, conn: DbConn) -> EmptyResult {
    Ok(())
}
