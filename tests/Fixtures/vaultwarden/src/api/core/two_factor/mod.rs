pub fn routes() -> Vec<Route> {
    routes![get_twofactor]
}

#[get("/two-factor")]
async fn get_twofactor(headers: Headers, conn: DbConn) -> Json<Value> {
    todo!()
}
