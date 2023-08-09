function recurser() {
    BX24.callMethod(   "crm.contact.list", {
            filter: { "OPENED": "N" },
            select: [ "OPENED", "ID" ]
        },
        function(result){
            if(result.data().length !== 0) {
                result.data().forEach((element) => {
                    console.log(element);
                    BX24.callMethod("crm.contact.update",
                        {
                            id: element.ID,
                            fields: {
                                "OPENED": "Y",
                            },
                        },
                    );
                });
                setTimeout(recurser, 3000);
            }
        });
}
recurser();
