pub fn routes() -> Vec<Route> {
    let mut routes = routes![alive];
    if CONFIG.web_vault_enabled() {
        routes.append(&mut routes![web_index, web_files]);
    }
    routes
}

#[get("/alive")]
fn alive(_conn: DbConn) -> Json<String> {
    now()
}

#[get("/")]
async fn web_index() -> Cached<Option<NamedFile>> {
    todo!()
}

#[get("/<p..>", rank = 10)] // Only match this if the other routes don't match
async fn web_files(p: PathBuf) -> Cached<Option<NamedFile>> {
    todo!()
}

#[get("/never-mounted")]
fn forgotten() {}
