pub fn routes() -> Vec<rocket::Route> {
    routes![
        register,
        // A comment between handlers
        get_public_keys,
        put_avatar,
    ]
}

#[post("/accounts/register", data = "<data>")]
async fn register(data: Json<RegisterData>, conn: DbConn) -> JsonResult {
    todo!()
}

#[expect(clippy::needless_pass_by_value, reason = "Not beneficial for Headers")]
#[get("/users/<user_id>/public-key?<include_keys>")]
async fn get_public_keys(user_id: UserId, include_keys: Option<bool>, _headers: Headers, conn: DbConn) -> JsonResult {
    todo!()
}

#[put("/accounts/avatar", data = "<data>")]
async fn put_avatar(
    data: Json<AvatarData>,
    headers: Headers,
    mut conn: DbConn,
    nt: Notify<'_>,
) -> JsonResult {
    todo!()
}
